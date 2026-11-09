<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProvisionVmJob;
use App\Models\Host;
use App\Models\Image;
use App\Models\IpPool;
use App\Models\User;
use App\Models\UserQuota;
use App\Services\Hypervisor\Drivers\FakeHypervisorDriver;
use App\Services\Hypervisor\HypervisorManager;
use App\Services\Hypervisor\IncusEventSyncService;
use App\Services\VmProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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

        $job = new ProvisionVmJob($vm, null, 12);
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
}
