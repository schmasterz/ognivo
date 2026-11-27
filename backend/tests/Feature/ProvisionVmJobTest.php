<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\IpAllocationStatus;
use App\Enums\NetworkStatus;
use App\Events\AdminVirtualMachineStatusUpdated;
use App\Events\VirtualMachineStatusUpdated;
use App\Jobs\ProvisionVmJob;
use App\Models\Host;
use App\Models\Image;
use App\Models\IpPool;
use App\Models\User;
use App\Models\UserQuota;
use App\Models\VirtualMachine;
use App\Services\Hypervisor\Drivers\FakeHypervisorDriver;
use App\Services\Hypervisor\HypervisorManager;
use App\Services\Hypervisor\InstanceSpecBuilder;
use App\Services\IpManagementService;
use App\States\Vm\Failed;
use App\States\Vm\Running;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ProvisionVmJobTest extends TestCase
{
    use RefreshDatabase;

    private Host $host;

    private User $user;

    private IpPool $pool;

    private FakeHypervisorDriver $driver;

    private HypervisorManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.allow_fake_driver' => true]);
        $this->driver = new FakeHypervisorDriver;
        HypervisorManager::fake($this->driver);
        $this->manager = app(HypervisorManager::class);

        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $this->host = Host::create([
            'name' => 'test-node-'.uniqid(),
            'endpoint_url' => 'https://127.0.0.1:8443',
            'driver' => 'fake',
            'status' => 'ok',
            'is_active' => true,
            'total_cpu_cores' => 32,
            'total_ram_mb' => 65536,
            'total_storage_gb' => 1000,
        ]);

        $this->user = User::factory()->create(['status' => 'active']);

        UserQuota::create([
            'user_id' => $this->user->id,
            'max_vms' => 5,
            'max_cpu_cores' => 16,
            'max_ram_mb' => 32768,
            'max_storage_gb' => 500,
            'max_ips' => 5,
        ]);

        $this->pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Test Pool '.uniqid(),
            'cidr' => '10.50.0.0/24',
            'first_usable' => '10.50.0.10',
            'last_usable' => '10.50.0.50',
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
            'active' => true,
            'is_public' => true,
        ]);
    }

    private function createVmWithIp(string $name = 'test-vm'): array
    {
        $vm = VirtualMachine::create([
            'user_id' => $this->user->id,
            'host_id' => $this->host->id,
            'name' => $name.'-'.uniqid(),
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => 'provisioning',
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu/24.04',
        ]);

        $allocation = app(IpManagementService::class)->allocateIp($this->pool, $vm, $this->user);

        return [$vm, $allocation];
    }

    public function test_observed_equals_assigned_marks_network_status_observed(): void
    {
        /** @var VirtualMachine $vm */
        [$vm, $allocation] = $this->createVmWithIp('vm-observed');

        $this->driver->setInstanceStatus($vm, 'Running');
        $this->driver->setInstanceState($vm, [
            'status' => 'Running',
            'network' => [
                'eth0' => [
                    'addresses' => [
                        [
                            'family' => 'inet',
                            'address' => $vm->ipv4_address,
                            'netmask' => '32',
                            'scope' => 'global',
                        ],
                    ],
                ],
            ],
        ]);

        $job = new ProvisionVmJob($vm, $allocation->id);
        $job->handle($this->manager);

        $vm->refresh();
        $this->assertTrue($vm->status->equals(Running::class));
        $this->assertSame(NetworkStatus::Observed, $vm->network_status);
    }

    public function test_observed_not_equals_assigned_marks_mismatch_and_vm_running(): void
    {
        /** @var VirtualMachine $vm */
        [$vm, $allocation] = $this->createVmWithIp('vm-mismatch');

        $this->driver->setInstanceStatus($vm, 'Running');
        $this->driver->setInstanceState($vm, [
            'status' => 'Running',
            'network' => [
                'eth0' => [
                    'addresses' => [
                        [
                            'family' => 'inet',
                            'address' => '192.168.1.199',
                            'netmask' => '32',
                            'scope' => 'global',
                        ],
                    ],
                ],
            ],
        ]);

        $job = new ProvisionVmJob($vm, $allocation->id);
        $job->handle($this->manager);

        $vm->refresh();
        $this->assertTrue($vm->status->equals(Running::class));
        $this->assertSame(NetworkStatus::Mismatch, $vm->network_status);
    }

    public function test_permanent_create_failure_marks_failed_cools_allocation_and_deletes_instance(): void
    {
        /** @var VirtualMachine $vm */
        [$vm, $allocation] = $this->createVmWithIp('vm-perm-fail');

        // Status Unknown and creation fails permanently
        $this->driver->setInstanceStatus($vm, 'Unknown');
        $this->driver->setFailAction('createInstance', true);

        $job = new ProvisionVmJob($vm, $allocation->id);

        // Permanent failure must NOT throw
        $job->handle($this->manager);

        $vm->refresh();
        $this->assertTrue($vm->status->equals(Failed::class));
        $this->assertSame(NetworkStatus::Unassigned, $vm->network_status);
        $this->assertIsArray($vm->metadata);
        $this->assertArrayHasKey('failure_reason', $vm->metadata);
        $this->assertStringContainsString('Hypervisor rejected instance creation', (string) $vm->metadata['failure_reason']);

        $allocation->refresh();
        $this->assertSame(IpAllocationStatus::Cooling, $allocation->status);
        $this->assertNull($allocation->virtual_machine_id);

        $this->assertTrue($this->driver->wasInstanceDeleted($vm));
    }

    public function test_transient_failure_retried_and_succeeds(): void
    {
        /** @var VirtualMachine $vm */
        [$vm, $allocation] = $this->createVmWithIp('vm-transient');

        $this->driver->setInstanceStatus($vm, 'Unknown');

        // First attempt throws a transient ConnectionException
        $attempts = 0;
        $this->driver->setActionCallback('createInstance', function (VirtualMachine $v) use (&$attempts): bool {
            $attempts++;
            if ($attempts === 1) {
                throw new ConnectionException('Connection to hypervisor socket timed out');
            }

            return true;
        });

        $job = new ProvisionVmJob($vm, $allocation->id);

        // Attempt 1: Transient error rethrown
        try {
            $job->handle($this->manager);
            $this->fail('Expected transient ConnectionException to be rethrown');
        } catch (ConnectionException $e) {
            $this->assertStringContainsString('timed out', $e->getMessage());
        }

        // VM must NOT be marked Failed after a transient failure
        $vm->refresh();
        $this->assertFalse($vm->status->equals(Failed::class));

        // Attempt 2: Retry succeeds
        $this->driver->setInstanceStatus($vm, 'Running');
        $this->driver->setInstanceState($vm, [
            'status' => 'Running',
            'network' => [
                'eth0' => [
                    'addresses' => [
                        [
                            'family' => 'inet',
                            'address' => $vm->ipv4_address,
                            'netmask' => '32',
                            'scope' => 'global',
                        ],
                    ],
                ],
            ],
        ]);

        $job->handle($this->manager);

        $vm->refresh();
        $this->assertTrue($vm->status->equals(Running::class));
        $this->assertSame(NetworkStatus::Observed, $vm->network_status);
    }

    public function test_job_payload_restored_from_queue_produces_same_behaviour(): void
    {
        config(['provisioning.network_wait_seconds' => 5]);

        /** @var VirtualMachine $vm */
        [$vm, $allocation] = $this->createVmWithIp('vm-payload');

        $this->driver->setInstanceStatus($vm, 'Running');
        $this->driver->setInstanceState($vm, [
            'status' => 'Running',
            'network' => [],
        ]);

        // Attempt 1: In-memory job starts with DB wait timestamp unset
        $job1 = new ProvisionVmJob($vm, $allocation->id);
        $job1->handle($this->manager);

        $vm->refresh();
        $this->assertNotNull($vm->network_wait_started_at);
        $this->assertSame(NetworkStatus::Assigned, $vm->network_status);

        // Simulate queue re-queue: restored job has original constructor args
        $serialized = serialize(new ProvisionVmJob($vm->fresh(), $allocation->id));
        /** @var ProvisionVmJob $jobRestored */
        $jobRestored = unserialize($serialized);

        // Run before deadline has elapsed -> still waiting, does not mark unverified prematurely
        $jobRestored->handle($this->manager);
        $vm->refresh();
        $this->assertSame(NetworkStatus::Assigned, $vm->network_status);

        // Fast forward network_wait_started_at past the 5s threshold
        $vm->update(['network_wait_started_at' => now()->subSeconds(6)]);

        // Restored job still succeeds in marking unverified based on DB timestamp
        $serialized2 = serialize(new ProvisionVmJob($vm->fresh(), $allocation->id));
        /** @var ProvisionVmJob $jobRestored2 */
        $jobRestored2 = unserialize($serialized2);

        $jobRestored2->handle($this->manager);

        $vm->refresh();
        $this->assertTrue($vm->status->equals(Running::class));
        $this->assertSame(NetworkStatus::Unverified, $vm->network_status);
    }

    public function test_broadcast_of_network_status_and_ip_changes_via_model_saved_hook(): void
    {
        Event::fake([
            VirtualMachineStatusUpdated::class,
            AdminVirtualMachineStatusUpdated::class,
        ]);

        [$vm] = $this->createVmWithIp('vm-broadcast');

        // Clear events from creation
        Event::fake([
            VirtualMachineStatusUpdated::class,
            AdminVirtualMachineStatusUpdated::class,
        ]);

        $vm->network_status = NetworkStatus::Observed;
        $vm->save();

        Event::assertDispatched(VirtualMachineStatusUpdated::class, function (VirtualMachineStatusUpdated $event) use ($vm) {
            return $event->vm['id'] === $vm->id && ($event->vm['network_status'] ?? null) === 'observed';
        });

        Event::assertDispatched(AdminVirtualMachineStatusUpdated::class, function (AdminVirtualMachineStatusUpdated $event) use ($vm) {
            return $event->vm['id'] === $vm->id && ($event->vm['network_status'] ?? null) === 'observed';
        });
    }

    public function test_spec_builder_domain_exception_marks_vm_failed_immediately_with_readable_reason(): void
    {
        Image::firstOrCreate(
            ['name' => 'ubuntu/24.04'],
            [
                'source' => 'https://images.linuxcontainers.org',
                'protocol' => 'simplestreams',
                'os_type' => 'linux',
                'enabled' => true,
            ]
        );

        $badPool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Empty Interface Pool',
            'cidr' => '10.60.0.0/24',
            'first_usable' => '10.60.0.10',
            'last_usable' => '10.60.0.50',
            'network_mode' => 'bridged',
            'parent_interface' => '',
            'active' => true,
            'is_public' => true,
        ]);

        $vm = VirtualMachine::create([
            'user_id' => $this->user->id,
            'host_id' => $this->host->id,
            'name' => 'vm-bad-spec-'.uniqid(),
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => 'provisioning',
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu/24.04',
        ]);

        $allocation = app(IpManagementService::class)->allocateIp($badPool, $vm, $this->user);

        $this->driver->setInstanceStatus($vm, 'Unknown');
        $this->driver->setActionCallback('createInstance', function (VirtualMachine $targetVm): bool {
            app(InstanceSpecBuilder::class)->build($targetVm);

            return true;
        });

        $job = new ProvisionVmJob($vm, $allocation->id);
        $job->handle($this->manager);

        $vm->refresh();
        $this->assertTrue($vm->status->equals(Failed::class));
        $this->assertSame(NetworkStatus::Unassigned, $vm->network_status);
        $this->assertIsArray($vm->metadata);
        $this->assertStringContainsString('parent_interface', (string) ($vm->metadata['raw_failure_reason'] ?? ''));

        $allocation->refresh();
        $this->assertSame(IpAllocationStatus::Cooling, $allocation->status);
    }
}
