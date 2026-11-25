<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ReinstallVmJob;
use App\Models\Host;
use App\Models\Image;
use App\Models\IpAllocation;
use App\Models\IpPool;
use App\Models\User;
use App\Models\UserQuota;
use App\Models\VirtualMachine;
use App\Services\Hypervisor\Drivers\FakeHypervisorDriver;
use App\Services\Hypervisor\HypervisorManager;
use App\States\Vm\Deleted;
use App\States\Vm\Deleting;
use App\States\Vm\Failed;
use App\States\Vm\Provisioning;
use App\States\Vm\Running;
use App\States\Vm\Stopped;
use App\States\Vm\Suspended;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class VmReinstallTest extends TestCase
{
    use RefreshDatabase;

    private User $tenant;

    private User $otherTenant;

    private User $admin;

    private Host $host;

    private IpPool $pool;

    private Image $linuxImage;

    private Image $otherLinuxImage;

    private Image $windowsImage;

    private Image $disabledImage;

    private FakeHypervisorDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.allow_fake_driver' => true]);
        $this->driver = new FakeHypervisorDriver;
        HypervisorManager::fake($this->driver);

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $this->tenant = User::factory()->create(['status' => 'active']);
        $this->tenant->assignRole('customer');

        $this->otherTenant = User::factory()->create(['status' => 'active']);
        $this->otherTenant->assignRole('customer');

        $this->admin = User::factory()->create(['status' => 'active']);
        $this->admin->assignRole('admin');

        UserQuota::create([
            'user_id' => $this->tenant->id,
            'max_vms' => 10,
            'max_cpu_cores' => 32,
            'max_ram_mb' => 65536,
            'max_storage_gb' => 1000,
            'max_ips' => 10,
            'max_snapshots' => 5,
        ]);

        $this->host = Host::create([
            'name' => 'compute-node-'.uniqid(),
            'endpoint_url' => 'https://127.0.0.1:8443',
            'driver' => 'fake',
            'status' => 'ok',
            'is_active' => true,
            'total_cpu_cores' => 32,
            'total_ram_mb' => 65536,
            'total_storage_gb' => 1000,
            'capacity_source' => 'discovered',
            'capacity_synced_at' => now(),
        ]);

        $this->pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Pool-'.uniqid(),
            'cidr' => '10.60.0.0/24',
            'first_usable' => '10.60.0.10',
            'last_usable' => '10.60.0.50',
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
            'is_public' => true,
        ]);

        $this->linuxImage = Image::firstOrCreate(
            ['name' => 'ubuntu/24.04'],
            [
                'source' => 'https://images.linuxcontainers.org',
                'protocol' => 'simplestreams',
                'os_type' => 'linux',
                'enabled' => true,
            ]
        );
        $this->linuxImage->hosts()->syncWithoutDetaching([$this->host->id]);

        $this->otherLinuxImage = Image::firstOrCreate(
            ['name' => 'debian/12'],
            [
                'source' => 'https://images.linuxcontainers.org',
                'protocol' => 'simplestreams',
                'os_type' => 'linux',
                'enabled' => true,
            ]
        );
        $this->otherLinuxImage->hosts()->syncWithoutDetaching([$this->host->id]);

        $this->windowsImage = Image::firstOrCreate(
            ['name' => 'windows/server-2022'],
            [
                'source' => 'https://images.linuxcontainers.org',
                'protocol' => 'simplestreams',
                'os_type' => 'windows',
                'enabled' => true,
            ]
        );
        $this->windowsImage->hosts()->syncWithoutDetaching([$this->host->id]);

        $this->disabledImage = Image::firstOrCreate(
            ['name' => 'archlinux/current'],
            [
                'source' => 'https://images.linuxcontainers.org',
                'protocol' => 'simplestreams',
                'os_type' => 'linux',
                'enabled' => false,
            ]
        );
        $this->disabledImage->hosts()->syncWithoutDetaching([$this->host->id]);

        $this->clearRateLimit($this->tenant);
        $this->clearRateLimit($this->otherTenant);
        $this->clearRateLimit($this->admin);
    }

    protected function tearDown(): void
    {
        HypervisorManager::resetFake();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createVm(array $attributes = []): VirtualMachine
    {
        /** @var array<model-property<VirtualMachine>, mixed> $data */
        $data = array_merge([
            'user_id' => $this->tenant->id,
            'host_id' => $this->host->id,
            'name' => 'vm-'.uniqid(),
            'incus_project' => 'user-'.$this->tenant->id,
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => $this->linuxImage->name,
            'ipv4_address' => '10.60.0.15',
            'network_status' => 'observed',
        ], $attributes);

        return VirtualMachine::create($data);
    }

    private function assignIp(VirtualMachine $vm, string $ipString = '10.60.0.15'): IpAllocation
    {
        /** @var IpAllocation $allocation */
        $allocation = IpAllocation::create([
            'pool_id' => $this->pool->id,
            'address' => inet_pton($ipString),
            'ip_string' => $ipString,
            'status' => 'allocated',
            'user_id' => $vm->user_id,
            'virtual_machine_id' => $vm->id,
            'allocated_at' => now(),
        ]);

        $vm->update(['ipv4_address' => $ipString]);

        return $allocation;
    }

    private function clearRateLimit(User $user): void
    {
        RateLimiter::clear('vm-actions:user:'.$user->id);
        RateLimiter::clear('vm-actions:'.$user->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function assertAuditLogEntry(
        string $action,
        string $outcome,
        ?int $tenantUserId = null,
        string $visibility = 'tenant',
        ?int $subjectId = null,
    ): array {
        $query = DB::table('audit_logs')
            ->where('action', $action)
            ->where('outcome', $outcome)
            ->where('visibility', $visibility);

        if ($tenantUserId !== null) {
            $query->where('tenant_user_id', $tenantUserId);
        } else {
            $query->whereNull('tenant_user_id');
        }

        if ($subjectId !== null) {
            $query->where('subject_id', $subjectId);
        }

        /** @var \stdClass|null $row */
        $row = $query->latest('id')->first();
        $this->assertNotNull($row, "Failed asserting audit_log row exists for [{$action}] with outcome [{$outcome}].");

        $this->assertNotEmpty($row->request_id, "Audit log row for [{$action}] has empty request_id.");

        $contextData = [];
        if (isset($row->context) && is_string($row->context)) {
            /** @var mixed $decoded */
            $decoded = json_decode($row->context, true);
            $this->assertIsArray($decoded);
            /** @var array<string, mixed> $contextData */
            $contextData = $decoded;
        }

        return $contextData;
    }

    public function test_unauthorized_user_cannot_reinstall_foreign_vm(): void
    {
        Queue::fake([ReinstallVmJob::class]);

        $vm = $this->createVm();
        $this->clearRateLimit($this->otherTenant);

        $response = $this->actingAs($this->otherTenant)
            ->postJson("/api/v1/vms/{$vm->id}/action", [
                'action' => 'reinstall',
            ]);

        $response->assertStatus(403);

        $this->assertAuditLogEntry(
            action: 'vm.action.denied',
            outcome: 'denied',
            tenantUserId: $this->tenant->id,
            subjectId: $vm->id,
        );

        Queue::assertNotPushed(ReinstallVmJob::class);

        $vm->refresh();
        $this->assertTrue($vm->status->equals(Running::class));
    }

    public function test_tenant_can_initiate_reinstall_on_own_vm(): void
    {
        Queue::fake([ReinstallVmJob::class]);

        $vm = $this->createVm();
        $this->clearRateLimit($this->tenant);

        $response = $this->actingAs($this->tenant)
            ->postJson("/api/v1/vms/{$vm->id}/action", [
                'action' => 'reinstall',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('virtual_machine.status', 'provisioning');

        $vm->refresh();
        $this->assertTrue($vm->status->equals(Provisioning::class));

        Queue::assertPushed(ReinstallVmJob::class, function (ReinstallVmJob $job) use ($vm): bool {
            return $job->vm->id === $vm->id;
        });

        $context = $this->assertAuditLogEntry(
            action: 'vm.reinstall.requested',
            outcome: 'success',
            tenantUserId: $this->tenant->id,
            subjectId: $vm->id,
        );
        $this->assertSame($vm->os_image, $context['os_image'] ?? null);
    }

    public function test_admin_can_reinstall_any_vm(): void
    {
        Queue::fake([ReinstallVmJob::class]);

        $vm = $this->createVm();
        $this->clearRateLimit($this->admin);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/vms/{$vm->id}/action", [
                'action' => 'reinstall',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('virtual_machine.status', 'provisioning');

        $vm->refresh();
        $this->assertTrue($vm->status->equals(Provisioning::class));

        Queue::assertPushed(ReinstallVmJob::class);
    }

    public function test_reinstall_rejected_in_invalid_states(): void
    {
        Queue::fake([ReinstallVmJob::class]);

        $invalidStates = [
            Provisioning::class,
            Deleting::class,
            Deleted::class,
        ];

        foreach ($invalidStates as $stateClass) {
            $vm = $this->createVm(['status' => $stateClass]);
            $this->clearRateLimit($this->tenant);

            $response = $this->actingAs($this->tenant)
                ->postJson("/api/v1/vms/{$vm->id}/action", [
                    'action' => 'reinstall',
                ]);

            $response->assertStatus(409);

            $context = $this->assertAuditLogEntry(
                action: 'vm.action.denied',
                outcome: 'denied',
                tenantUserId: $this->tenant->id,
                subjectId: $vm->id,
            );
            $this->assertSame('reinstall', $context['action'] ?? null);
            $this->assertSame('invalid_state_transition', $context['reason'] ?? null);
        }

        Queue::assertNotPushed(ReinstallVmJob::class);
    }

    public function test_reinstall_allowed_in_valid_states(): void
    {
        Queue::fake([ReinstallVmJob::class]);

        $validStates = [
            Running::class,
            Stopped::class,
            Suspended::class,
            Failed::class,
        ];

        foreach ($validStates as $stateClass) {
            $vm = $this->createVm(['status' => $stateClass]);
            $this->clearRateLimit($this->tenant);

            $response = $this->actingAs($this->tenant)
                ->postJson("/api/v1/vms/{$vm->id}/action", [
                    'action' => 'reinstall',
                ]);

            $response->assertStatus(200);
            $response->assertJsonPath('virtual_machine.status', 'provisioning');

            $vm->refresh();
            $this->assertTrue($vm->status->equals(Provisioning::class));
        }

        Queue::assertPushed(ReinstallVmJob::class, count($validStates));
    }

    public function test_reinstall_with_disabled_image_returns_422(): void
    {
        Queue::fake([ReinstallVmJob::class]);

        $vm = $this->createVm();
        $this->clearRateLimit($this->tenant);

        $response = $this->actingAs($this->tenant)
            ->postJson("/api/v1/vms/{$vm->id}/action", [
                'action' => 'reinstall',
                'os_image' => $this->disabledImage->name,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['os_image']);
        Queue::assertNotPushed(ReinstallVmJob::class);
    }

    public function test_reinstall_with_unknown_image_returns_422(): void
    {
        Queue::fake([ReinstallVmJob::class]);

        $vm = $this->createVm();
        $this->clearRateLimit($this->tenant);

        $response = $this->actingAs($this->tenant)
            ->postJson("/api/v1/vms/{$vm->id}/action", [
                'action' => 'reinstall',
                'os_image' => 'unknown/image-does-not-exist',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['os_image']);
        Queue::assertNotPushed(ReinstallVmJob::class);
    }

    public function test_reinstall_container_with_non_linux_image_returns_422(): void
    {
        Queue::fake([ReinstallVmJob::class]);

        $vm = $this->createVm(['instance_type' => 'container']);
        $this->clearRateLimit($this->tenant);

        $response = $this->actingAs($this->tenant)
            ->postJson("/api/v1/vms/{$vm->id}/action", [
                'action' => 'reinstall',
                'os_image' => $this->windowsImage->name,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['os_image']);
        $response->assertJsonFragment([
            'os_image' => ['Containers (LXC) can only run Linux operating system images.'],
        ]);
        Queue::assertNotPushed(ReinstallVmJob::class);
    }

    public function test_reinstall_container_with_linux_image_succeeds(): void
    {
        Queue::fake([ReinstallVmJob::class]);

        $vm = $this->createVm(['instance_type' => 'container']);
        $this->clearRateLimit($this->tenant);

        $response = $this->actingAs($this->tenant)
            ->postJson("/api/v1/vms/{$vm->id}/action", [
                'action' => 'reinstall',
                'os_image' => $this->otherLinuxImage->name,
            ]);

        $response->assertStatus(200);
        $vm->refresh();
        $this->assertTrue($vm->status->equals(Provisioning::class));
        Queue::assertPushed(ReinstallVmJob::class);
    }

    public function test_reinstall_vm_with_non_linux_image_succeeds(): void
    {
        Queue::fake([ReinstallVmJob::class]);

        $vm = $this->createVm(['instance_type' => 'virtual-machine']);
        $this->clearRateLimit($this->tenant);

        $response = $this->actingAs($this->tenant)
            ->postJson("/api/v1/vms/{$vm->id}/action", [
                'action' => 'reinstall',
                'os_image' => $this->windowsImage->name,
            ]);

        $response->assertStatus(200);
        $vm->refresh();
        $this->assertTrue($vm->status->equals(Provisioning::class));
        Queue::assertPushed(ReinstallVmJob::class);
    }

    public function test_reinstall_fails_when_host_is_inactive(): void
    {
        Queue::fake([ReinstallVmJob::class]);

        $inactiveHost = Host::create([
            'name' => 'inactive-node-'.uniqid(),
            'endpoint_url' => 'https://127.0.0.1:8444',
            'driver' => 'fake',
            'status' => 'error',
            'is_active' => false,
            'total_cpu_cores' => 32,
            'total_ram_mb' => 65536,
            'total_storage_gb' => 1000,
            'capacity_source' => 'discovered',
            'capacity_synced_at' => now(),
        ]);

        $vm = $this->createVm(['host_id' => $inactiveHost->id]);
        $this->clearRateLimit($this->tenant);

        $response = $this->actingAs($this->tenant)
            ->postJson("/api/v1/vms/{$vm->id}/action", [
                'action' => 'reinstall',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['host_id']);
        Queue::assertNotPushed(ReinstallVmJob::class);
    }

    public function test_reinstall_preserves_ip_allocation_and_address(): void
    {
        Queue::fake([ReinstallVmJob::class]);

        $vm = $this->createVm();
        $allocation = $this->assignIp($vm, '10.60.0.22');
        $this->clearRateLimit($this->tenant);

        $response = $this->actingAs($this->tenant)
            ->postJson("/api/v1/vms/{$vm->id}/action", [
                'action' => 'reinstall',
            ]);

        $response->assertStatus(200);

        // Before job executes, IP remains assigned to VM
        $vm->refresh();
        $allocation->refresh();
        $this->assertSame('10.60.0.22', $vm->ipv4_address);
        $this->assertSame('allocated', $allocation->status);
        $this->assertSame($vm->id, $allocation->virtual_machine_id);

        // Execute ReinstallVmJob directly
        $manager = app(HypervisorManager::class);
        $job = new ReinstallVmJob($vm);
        $job->handle($manager);

        // After job completes, IP is still preserved
        $vm->refresh();
        $allocation->refresh();
        $this->assertSame('10.60.0.22', $vm->ipv4_address);
        $this->assertSame('allocated', $allocation->status);
        $this->assertSame($vm->id, $allocation->virtual_machine_id);
    }

    public function test_end_to_end_reinstall_transitions_vm_to_running_and_updates_configuration(): void
    {
        $vm = $this->createVm([
            'status' => Running::class,
            'os_image' => $this->linuxImage->name,
            'cloud_init' => ['user-data' => '#old-cloud-config'],
        ]);
        $allocation = $this->assignIp($vm, '10.60.0.25');

        $this->driver->setInstanceStatus($vm, 'Unknown');
        $this->driver->setActionCallback('createInstance', function (VirtualMachine $createdVm): bool {
            $this->driver->setInstanceStatus($createdVm, 'Stopped');

            return true;
        });
        $this->driver->setActionCallback('startInstance', function (VirtualMachine $startedVm): bool {
            $this->driver->setInstanceStatus($startedVm, 'Running');

            return true;
        });

        $newImage = $this->otherLinuxImage->name;
        $newCloudInit = ['user-data' => '#new-cloud-config'];

        $job = new ReinstallVmJob($vm, $newImage, $newCloudInit, $allocation->id);
        $job->handle(app(HypervisorManager::class));

        $this->assertTrue($this->driver->wasInstanceDeleted($vm));

        $vm->refresh();
        $this->assertTrue($vm->status->equals(Running::class));
        $this->assertSame($newImage, $vm->os_image);
        $this->assertSame($newCloudInit, $vm->cloud_init);
        $this->assertSame('observed', $vm->network_status);
        $this->assertSame('10.60.0.25', $vm->ipv4_address);

        $allocation->refresh();
        $this->assertSame('allocated', $allocation->status);
        $this->assertSame($vm->id, $allocation->virtual_machine_id);
    }

    public function test_reinstall_vm_job_can_be_dispatched_sync(): void
    {
        $vm = $this->createVm([
            'status' => Stopped::class,
            'os_image' => $this->linuxImage->name,
        ]);
        $this->assignIp($vm, '10.60.0.30');

        $newImage = $this->otherLinuxImage->name;
        $newCloudInit = ['user-data' => '#sync-dispatched-config'];

        ReinstallVmJob::dispatchSync($vm, $newImage, $newCloudInit);

        $this->assertTrue($this->driver->wasInstanceDeleted($vm));

        $vm->refresh();
        $this->assertTrue($vm->status->equals(Running::class));
        $this->assertSame($newImage, $vm->os_image);
        $this->assertSame($newCloudInit, $vm->cloud_init);
        $this->assertSame('observed', $vm->network_status);
    }

    public function test_audit_log_records_reinstall_requested_with_image_context(): void
    {
        Queue::fake();

        $vm1 = $this->createVm(['os_image' => $this->linuxImage->name]);
        $this->clearRateLimit($this->tenant);

        // 1. Reinstall with explicitly provided image
        $this->actingAs($this->tenant)
            ->postJson("/api/v1/vms/{$vm1->id}/action", [
                'action' => 'reinstall',
                'os_image' => $this->otherLinuxImage->name,
            ])
            ->assertStatus(200);

        $contextWithImage = $this->assertAuditLogEntry(
            action: 'vm.reinstall.requested',
            outcome: 'success',
            tenantUserId: $this->tenant->id,
            subjectId: $vm1->id,
        );
        $this->assertSame($this->otherLinuxImage->name, $contextWithImage['os_image'] ?? null);

        // 2. Reinstall with no image specified keeps existing vm image in audit log
        $vm2 = $this->createVm(['os_image' => $this->linuxImage->name]);
        $this->clearRateLimit($this->tenant);

        $this->actingAs($this->tenant)
            ->postJson("/api/v1/vms/{$vm2->id}/action", [
                'action' => 'reinstall',
            ])
            ->assertStatus(200);

        $contextWithoutImage = $this->assertAuditLogEntry(
            action: 'vm.reinstall.requested',
            outcome: 'success',
            tenantUserId: $this->tenant->id,
            subjectId: $vm2->id,
        );
        $this->assertSame($this->linuxImage->name, $contextWithoutImage['os_image'] ?? null);
    }

    public function test_reinstall_polling_preserves_instance_across_multiple_releases_until_network_observed(): void
    {
        $vm = $this->createVm([
            'status' => Running::class,
            'os_image' => $this->linuxImage->name,
            'cloud_init' => ['user-data' => '#old-config'],
        ]);
        $allocation = $this->assignIp($vm, '10.60.0.35');

        $this->driver->setInstanceStatus($vm, 'Unknown');
        $this->driver->setActionCallback('createInstance', function (VirtualMachine $createdVm): bool {
            $this->driver->setInstanceStatus($createdVm, 'Stopped');

            return true;
        });
        $this->driver->setActionCallback('startInstance', function (VirtualMachine $startedVm): bool {
            $this->driver->setInstanceStatus($startedVm, 'Running');

            return true;
        });
        // First execution: VM boots without an observed IP yet
        $this->driver->setInstanceState($vm, [
            'status' => 'Running',
            'network' => [],
        ]);

        $newImage = $this->otherLinuxImage->name;
        $newCloudInit = ['user-data' => '#new-config'];
        $manager = app(HypervisorManager::class);

        $job = new ReinstallVmJob($vm, $newImage, $newCloudInit, $allocation->id);
        // Simulate Redis queue payload serialization before first execution
        $serializedJob = serialize($job);

        $job->handle($manager);

        $this->assertTrue($this->driver->wasInstanceDeleted($vm));
        $deleteCount = count(array_filter(
            $this->driver->deletedInstances,
            static fn (string $name): bool => $name === $vm->name
        ));
        $this->assertSame(1, $deleteCount);

        $vm->refresh();
        $this->assertTrue($vm->status->equals(Provisioning::class));
        $this->assertSame('assigned', $vm->network_status);
        $this->assertSame($newImage, $vm->os_image);
        $this->assertSame($newCloudInit, $vm->cloud_init);
        $this->assertIsArray($vm->metadata);
        $this->assertTrue($vm->metadata['reinstall_in_progress'] ?? false);
        $this->assertNotNull($vm->network_wait_started_at);
        $initialWaitStartedAt = $vm->network_wait_started_at->getTimestamp();

        // Second execution (still no observed IP): unserialize original payload so $instanceDeleted === false
        $this->travel(15)->seconds();
        $secondPollJob = unserialize($serializedJob);
        $this->assertInstanceOf(ReinstallVmJob::class, $secondPollJob);
        $secondPollJob->handle($manager);

        $deleteCountAfterSecondPoll = count(array_filter(
            $this->driver->deletedInstances,
            static fn (string $name): bool => $name === $vm->name
        ));
        $this->assertSame(1, $deleteCountAfterSecondPoll);

        $vm->refresh();
        $this->assertTrue($vm->status->equals(Provisioning::class));
        $this->assertNotNull($vm->network_wait_started_at);
        $this->assertSame($initialWaitStartedAt, $vm->network_wait_started_at->getTimestamp());
        $this->assertIsArray($vm->metadata);
        $this->assertTrue($vm->metadata['reinstall_in_progress'] ?? false);

        // Third execution: hypervisor now reports the expected IPv4 address
        $this->driver->setInstanceState($vm, [
            'status' => 'Running',
            'network' => [
                'eth0' => [
                    'addresses' => [
                        [
                            'family' => 'inet',
                            'address' => '10.60.0.35',
                            'netmask' => '32',
                            'scope' => 'global',
                        ],
                    ],
                ],
            ],
        ]);

        $thirdPollJob = unserialize($serializedJob);
        $this->assertInstanceOf(ReinstallVmJob::class, $thirdPollJob);
        $thirdPollJob->handle($manager);

        $deleteCountAfterThirdPoll = count(array_filter(
            $this->driver->deletedInstances,
            static fn (string $name): bool => $name === $vm->name
        ));
        $this->assertSame(1, $deleteCountAfterThirdPoll);

        $vm->refresh();
        $this->assertTrue($vm->status->equals(Running::class));
        $this->assertSame('observed', $vm->network_status);
        $this->assertIsArray($vm->metadata);
        $this->assertArrayNotHasKey('reinstall_in_progress', $vm->metadata);

        $allocation->refresh();
        $this->assertSame('allocated', $allocation->status);
        $this->assertSame($vm->id, $allocation->virtual_machine_id);

        $this->travelBack();
    }

    public function test_reinstall_polling_completes_as_unverified_after_wait_limit(): void
    {
        config(['provisioning.network_wait_seconds' => 30]);

        $vm = $this->createVm(['status' => Running::class]);
        $allocation = $this->assignIp($vm, '10.60.0.36');

        $this->driver->setInstanceStatus($vm, 'Unknown');
        $this->driver->setActionCallback('createInstance', function (VirtualMachine $createdVm): bool {
            $this->driver->setInstanceStatus($createdVm, 'Stopped');

            return true;
        });
        $this->driver->setActionCallback('startInstance', function (VirtualMachine $startedVm): bool {
            $this->driver->setInstanceStatus($startedVm, 'Running');

            return true;
        });
        $this->driver->setInstanceState($vm, [
            'status' => 'Running',
            'network' => [],
        ]);

        $manager = app(HypervisorManager::class);
        $job = new ReinstallVmJob($vm, null, null, $allocation->id);
        $serializedJob = serialize($job);

        $job->handle($manager);

        $this->travel(35)->seconds();
        $requeuedJob = unserialize($serializedJob);
        $this->assertInstanceOf(ReinstallVmJob::class, $requeuedJob);
        $requeuedJob->handle($manager);

        $deleteCount = count(array_filter(
            $this->driver->deletedInstances,
            static fn (string $name): bool => $name === $vm->name
        ));
        $this->assertSame(1, $deleteCount);

        $vm->refresh();
        $this->assertTrue($vm->status->equals(Running::class));
        $this->assertSame('unverified', $vm->network_status);
        $this->assertIsArray($vm->metadata);
        $this->assertArrayNotHasKey('reinstall_in_progress', $vm->metadata);

        $this->travelBack();
    }

    public function test_reinstall_failure_cleans_up_reinstall_flag_and_preserves_ip(): void
    {
        $vm = $this->createVm(['status' => Running::class]);
        $allocation = $this->assignIp($vm, '10.60.0.37');

        $this->driver->setInstanceStatus($vm, 'Unknown');
        $this->driver->shouldFail['createInstance'] = true;

        $job = new ReinstallVmJob($vm, null, null, $allocation->id);
        $job->handle(app(HypervisorManager::class));

        $vm->refresh();
        $this->assertTrue($vm->status->equals(Failed::class));
        $this->assertIsArray($vm->metadata);
        $this->assertArrayNotHasKey('reinstall_in_progress', $vm->metadata);
        $this->assertArrayHasKey('failure_reason', $vm->metadata);
        $this->assertArrayHasKey('failed_at', $vm->metadata);

        $allocation->refresh();
        $this->assertSame('allocated', $allocation->status);
        $this->assertSame($vm->id, $allocation->virtual_machine_id);
        $this->assertSame('10.60.0.37', $vm->ipv4_address);
    }
}
