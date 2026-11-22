<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProvisionVmJob;
use App\Models\DriftReport;
use App\Models\Host;
use App\Models\Image;
use App\Models\IpPool;
use App\Models\User;
use App\Models\UserQuota;
use App\Services\Hypervisor\Contracts\HypervisorDriverInterface;
use App\Services\Hypervisor\Drivers\FakeHypervisorDriver;
use App\Services\Hypervisor\HypervisorManager;
use App\Services\Hypervisor\IncusEventSyncService;
use App\Services\ProvisioningCompensator;
use App\Services\VmProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ProvisioningLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Host $host;

    private User $user;

    private IpPool $pool;

    private Image $image;

    private FakeHypervisorDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.allow_fake_driver' => true]);
        $this->driver = new FakeHypervisorDriver;
        HypervisorManager::fake($this->driver);

        $this->host = Host::create([
            'name' => 'compute-test-node',
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

        $this->user = User::factory()->create([
            'status' => 'active',
        ]);

        UserQuota::create([
            'user_id' => $this->user->id,
            'max_vms' => 5,
            'max_cpu_cores' => 16,
            'max_ram_mb' => 32768,
            'max_storage_gb' => 200,
            'max_ips' => 5,
        ]);

        $this->pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Host Compute Pool',
            'cidr' => '203.0.113.0/28',
            'first_usable' => '203.0.113.2',
            'last_usable' => '203.0.113.14',
            'gateway' => '203.0.113.1',
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
            'active' => true,
        ]);

        $this->image = Image::firstOrCreate(
            ['name' => 'ubuntu/24.04'],
            [
                'source' => 'https://images.linuxcontainers.org',
                'protocol' => 'simplestreams',
                'os_type' => 'linux',
                'enabled' => true,
            ]
        );
        $this->image->hosts()->syncWithoutDetaching([$this->host->id]);
    }

    protected function tearDown(): void
    {
        HypervisorManager::resetFake();
        parent::tearDown();
    }

    public function test_provision_service_allocates_ip_synchronously_and_dispatches_job(): void
    {
        Queue::fake();

        /** @var VmProvisioningService $service */
        $service = app(VmProvisioningService::class);

        $vm = $service->provision($this->user, [
            'name' => 'web-srv-1',
            'cpu_cores' => 2,
            'ram_mb' => 4096,
            'disk_gb' => 20,
            'os_image' => 'ubuntu/24.04',
            'host_id' => $this->host->id,
            'instance_type' => 'virtual-machine',
        ]);

        $this->assertEquals('203.0.113.2', $vm->ipv4_address);
        $this->assertEquals('assigned', $vm->network_status);
        $this->assertEquals('provisioning', (string) $vm->status);

        $this->assertDatabaseHas('ip_allocations', [
            'virtual_machine_id' => $vm->id,
            'ip_string' => '203.0.113.2',
            'status' => 'allocated',
        ]);

        Queue::assertPushed(ProvisionVmJob::class, function (ProvisionVmJob $job) use ($vm): bool {
            return $job->vm->id === $vm->id;
        });
    }

    public function test_provision_job_verifies_observed_network_and_completes(): void
    {
        /** @var VmProvisioningService $service */
        $service = app(VmProvisioningService::class);

        $vm = $service->provision($this->user, [
            'name' => 'web-srv-observed',
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'ubuntu/24.04',
            'host_id' => $this->host->id,
        ]);

        $manager = app(HypervisorManager::class);
        $job = new ProvisionVmJob($vm);
        $job->handle($manager);

        $vm->refresh();
        $this->assertEquals('running', (string) $vm->status);
        $this->assertEquals('observed', $vm->network_status);
    }

    public function test_provision_job_marks_unverified_when_network_timeout_exceeded(): void
    {
        Queue::fake([ProvisionVmJob::class]);

        /** @var VmProvisioningService $service */
        $service = app(VmProvisioningService::class);

        $vm = $service->provision($this->user, [
            'name' => 'web-srv-timeout',
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'ubuntu/24.04',
            'host_id' => $this->host->id,
        ]);

        $manager = app(HypervisorManager::class);
        /** @var FakeHypervisorDriver $driver */
        $driver = $manager->driverForVm($vm);

        // Mock state with no network addresses visible (e.g. no agent)
        $driver->instanceStates[$vm->name] = [
            'status' => 'Running',
            'network' => [],
        ];

        // Simulate network timeout reached via database timestamp
        $vm->update(['network_wait_started_at' => now()->subSeconds(125)]);

        $job = new ProvisionVmJob($vm);
        $job->handle($manager);

        $vm->refresh();
        $this->assertEquals('running', (string) $vm->status);
        $this->assertEquals('unverified', $vm->network_status);
    }

    public function test_event_sync_does_not_cancel_provisioning_state_on_instance_created(): void
    {
        Queue::fake([ProvisionVmJob::class]);

        /** @var VmProvisioningService $service */
        $service = app(VmProvisioningService::class);

        $vm = $service->provision($this->user, [
            'name' => 'vm-async-event',
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu/24.04',
            'host_id' => $this->host->id,
        ]);

        $this->assertEquals('provisioning', (string) $vm->status);

        // Incus event daemon receives instance-created
        /** @var IncusEventSyncService $eventSync */
        $eventSync = app(IncusEventSyncService::class);

        $eventSync->handleLifecycleEvent($this->host, [
            'type' => 'lifecycle',
            'metadata' => [
                'action' => 'instance-created',
                'source' => '/1.0/instances/vm-async-event',
                'project' => $vm->incus_project,
            ],
        ]);

        $vm->refresh();
        // Crucial test: Status must remain 'provisioning', NOT forced to 'Stopped'!
        $this->assertEquals('provisioning', (string) $vm->status);
    }

    public function test_owner_can_retrieve_console_boot_log(): void
    {
        /** @var VmProvisioningService $service */
        $service = app(VmProvisioningService::class);

        $vm = $service->provision($this->user, [
            'name' => 'vm-boot-log',
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu/24.04',
            'host_id' => $this->host->id,
        ]);

        // Unauthorized user cannot access
        $otherUser = User::factory()->create();
        $this->actingAs($otherUser)->getJson("/api/vms/{$vm->id}/console-log")->assertStatus(403);

        // Owner can access
        $response = $this->actingAs($this->user)->getJson("/api/vms/{$vm->id}/console-log");
        $response->assertOk();
        $response->assertJsonPath('virtual_machine_id', $vm->id);
        $response->assertJsonPath('name', 'vm-boot-log');
        $this->assertStringContainsString('Linux version', (string) $response->json('output'));
        $this->assertStringContainsString('Netplan v2', (string) $response->json('output'));
    }

    public function test_compensation_retains_ip_allocation_and_creates_drift_when_delete_instance_fails(): void
    {
        Queue::fake([ProvisionVmJob::class]);

        /** @var VmProvisioningService $service */
        $service = app(VmProvisioningService::class);

        $vm = $service->provision($this->user, [
            'name' => 'vm-compensation-delete-fail',
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu/24.04',
            'host_id' => $this->host->id,
        ]);

        $ip = $vm->ipv4_address;
        $this->assertNotNull($ip);
        $this->assertDatabaseHas('ip_allocations', [
            'virtual_machine_id' => $vm->id,
            'ip_string' => $ip,
            'status' => 'allocated',
        ]);

        // Mock hypervisor driver throwing non-404 during deleteInstance
        $driverWithDeleteError = Mockery::mock(HypervisorDriverInterface::class);
        $driverWithDeleteError->shouldReceive('deleteInstance')
            ->once()
            ->andThrow(new \RuntimeException('Hypervisor connection refused: https://192.168.1.50:8443'));
        HypervisorManager::fake($driverWithDeleteError);

        ProvisioningCompensator::compensate(
            $vm,
            'Hypervisor instance creation failed: SSL error at https://192.168.1.50:8443'
        );

        $vm->refresh();
        $this->assertSame('failed', (string) $vm->status);

        // IP allocation MUST NOT be released
        $this->assertDatabaseHas('ip_allocations', [
            'virtual_machine_id' => $vm->id,
            'ip_string' => $ip,
            'status' => 'allocated',
        ]);

        // DriftReport must be recorded
        $drift = DriftReport::where('virtual_machine_id', $vm->id)->first();
        $this->assertNotNull($drift);
        $this->assertSame('cleanup_failed', $drift->kind);
        $this->assertSame($this->host->id, $drift->host_id);
    }

    public function test_compensation_retains_ip_when_delete_instance_returns_false(): void
    {
        Queue::fake([ProvisionVmJob::class]);

        /** @var VmProvisioningService $service */
        $service = app(VmProvisioningService::class);

        $vm = $service->provision($this->user, [
            'name' => 'vm-delete-returns-false',
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu/24.04',
            'host_id' => $this->host->id,
        ]);

        $ip = $vm->ipv4_address;
        $this->assertNotNull($ip);

        // Configure fake driver to fail deleteInstance
        $this->driver->shouldFail['deleteInstance'] = true;

        ProvisioningCompensator::compensate($vm, 'Instance startup failed on compute node');

        $this->assertDatabaseHas('ip_allocations', [
            'virtual_machine_id' => $vm->id,
            'ip_string' => $ip,
            'status' => 'allocated',
        ]);

        $drift = DriftReport::where('virtual_machine_id', $vm->id)->first();
        $this->assertNotNull($drift);
        $this->assertSame('cleanup_failed', $drift->kind);
    }

    public function test_tenant_response_for_failed_vm_does_not_leak_raw_hypervisor_details(): void
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $this->user->assignRole('customer');

        Queue::fake([ProvisionVmJob::class]);

        /** @var VmProvisioningService $service */
        $service = app(VmProvisioningService::class);

        $vm = $service->provision($this->user, [
            'name' => 'vm-failed-leak-test',
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu/24.04',
            'host_id' => $this->host->id,
        ]);

        $rawError = 'Hypervisor internal crash: connection to https://internal-node-42.cluster.lan:8443 token=secr3tKey failed with #0 /var/www/internal/Driver.php(52)';
        ProvisioningCompensator::compensate($vm, $rawError);

        $vm->refresh();
        $this->assertSame('failed', (string) $vm->status);

        // 1. Tenant request must not leak raw error or incus_project
        $tenantRes = $this->actingAs($this->user)->getJson("/api/vms/{$vm->id}");
        $tenantRes->assertOk();

        $data = $tenantRes->json();
        $vmData = $data['data'] ?? $data;

        $this->assertArrayNotHasKey('incus_project', $vmData);
        $this->assertArrayNotHasKey('raw_failure_reason', $vmData);
        $this->assertSame(
            'Instance provisioning failed. Please retry or contact support.',
            $vmData['failure_reason']
        );

        $body = (string) $tenantRes->getContent();
        $this->assertStringNotContainsString('internal-node-42', $body);
        $this->assertStringNotContainsString('secr3tKey', $body);
        $this->assertStringNotContainsString('/var/www/internal', $body);

        // 2. Admin request should see the full / raw failure reason and incus_project
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');

        $adminRes = $this->actingAs($admin)->getJson("/api/vms/{$vm->id}");
        $adminRes->assertOk();

        $adminData = $adminRes->json();
        $adminVmData = $adminData['data'] ?? $adminData;

        $this->assertSame($vm->incus_project, $adminVmData['incus_project']);
        $this->assertSame($rawError, $adminVmData['failure_reason']);
        $this->assertSame($rawError, $adminVmData['raw_failure_reason']);
    }
}
