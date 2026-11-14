<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Host;
use App\Models\Image;
use App\Models\IpPool;
use App\Models\User;
use App\Models\UserQuota;
use App\Models\VirtualMachine;
use App\Services\Hypervisor\Drivers\FakeHypervisorDriver;
use App\Services\Hypervisor\HypervisorManager;
use App\Services\VmProvisioningService;
use App\States\Vm\Failed;
use App\States\Vm\Provisioning;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class SweepStaleProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private Host $host;

    private User $user;

    private IpPool $pool;

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
            'max_vms' => 10,
            'max_cpu_cores' => 32,
            'max_ram_mb' => 65536,
            'max_storage_gb' => 500,
            'max_ips' => 10,
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

        $image = Image::firstOrCreate(
            ['name' => 'ubuntu/24.04'],
            [
                'source' => 'https://images.linuxcontainers.org',
                'protocol' => 'simplestreams',
                'os_type' => 'linux',
                'enabled' => true,
            ]
        );
        $image->hosts()->syncWithoutDetaching([$this->host->id]);
    }

    protected function tearDown(): void
    {
        HypervisorManager::resetFake();
        parent::tearDown();
    }

    public function test_stale_vm_in_provisioning_is_failed_and_ip_released_while_fresh_vm_is_untouched(): void
    {
        Queue::fake();

        /** @var VmProvisioningService $service */
        $service = app(VmProvisioningService::class);

        $staleVm = $service->provision($this->user, [
            'name' => 'stale-vm',
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'ubuntu/24.04',
            'host_id' => $this->host->id,
            'instance_type' => 'virtual-machine',
        ]);

        $freshVm = $service->provision($this->user, [
            'name' => 'fresh-vm',
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'ubuntu/24.04',
            'host_id' => $this->host->id,
            'instance_type' => 'virtual-machine',
        ]);

        $staleIp = $staleVm->ipv4_address;
        $freshIp = $freshVm->ipv4_address;

        $this->assertNotNull($staleIp);
        $this->assertNotNull($freshIp);
        $this->assertNotEquals($staleIp, $freshIp);

        // Age the stale VM past default deadline (900s + 120s = 1020s)
        VirtualMachine::where('id', $staleVm->id)->update([
            'updated_at' => now()->subSeconds(1200),
        ]);

        // Run the sweeper command
        $this->artisan('vm:sweep-stale-provisioning')
            ->expectsOutputToContain('Swept 1 stale provisioning VM(s).')
            ->assertSuccessful();

        // 1. Verify stale VM is transitioned to Failed and compensated
        $staleVm->refresh();
        $this->assertTrue($staleVm->status->equals(Failed::class));
        $this->assertSame('unassigned', $staleVm->network_status);
        $this->assertNull($staleVm->ipv4_address);
        $this->assertIsArray($staleVm->metadata);
        $this->assertSame(
            'Provisioning timed out (stale provisioning sweeper).',
            $staleVm->metadata['raw_failure_reason'] ?? null
        );

        // Verify stale VM's IP allocation was released to cooling
        $this->assertDatabaseMissing('ip_allocations', [
            'virtual_machine_id' => $staleVm->id,
            'status' => 'allocated',
        ]);
        $this->assertDatabaseHas('ip_allocations', [
            'ip_string' => $staleIp,
            'status' => 'cooling',
            'virtual_machine_id' => null,
        ]);

        // 2. Verify fresh VM is untouched
        $freshVm->refresh();
        $this->assertTrue($freshVm->status->equals(Provisioning::class));
        $this->assertSame('assigned', $freshVm->network_status);
        $this->assertSame($freshIp, $freshVm->ipv4_address);
        $this->assertDatabaseHas('ip_allocations', [
            'virtual_machine_id' => $freshVm->id,
            'ip_string' => $freshIp,
            'status' => 'allocated',
        ]);
    }

    public function test_stale_vm_with_held_lock_is_skipped(): void
    {
        Queue::fake();

        /** @var VmProvisioningService $service */
        $service = app(VmProvisioningService::class);

        $staleVm = $service->provision($this->user, [
            'name' => 'locked-stale-vm',
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'ubuntu/24.04',
            'host_id' => $this->host->id,
            'instance_type' => 'virtual-machine',
        ]);

        VirtualMachine::where('id', $staleVm->id)->update([
            'updated_at' => now()->subSeconds(1200),
        ]);

        // Simulate an active ProvisionVmJob or ReinstallVmJob holding the lock
        $lock = Cache::lock("vm:{$staleVm->id}", 30);
        $this->assertTrue($lock->get());

        try {
            $this->artisan('vm:sweep-stale-provisioning')
                ->expectsOutputToContain("Skipping VM #{$staleVm->id}")
                ->expectsOutputToContain('Swept 0 stale provisioning VM(s).')
                ->assertSuccessful();

            $staleVm->refresh();
            $this->assertTrue($staleVm->status->equals(Provisioning::class));
            $this->assertSame('assigned', $staleVm->network_status);
            $this->assertNotNull($staleVm->ipv4_address);
        } finally {
            $lock->release();
        }
    }

    public function test_max_age_seconds_option_overrides_cutoff(): void
    {
        Queue::fake();

        /** @var VmProvisioningService $service */
        $service = app(VmProvisioningService::class);

        $vm = $service->provision($this->user, [
            'name' => 'custom-cutoff-vm',
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'ubuntu/24.04',
            'host_id' => $this->host->id,
            'instance_type' => 'virtual-machine',
        ]);

        // Age VM by 60 seconds
        VirtualMachine::where('id', $vm->id)->update([
            'updated_at' => now()->subSeconds(60),
        ]);

        // Default cutoff (1020s) leaves it untouched
        $this->artisan('vm:sweep-stale-provisioning')
            ->expectsOutputToContain('Swept 0 stale provisioning VM(s).')
            ->assertSuccessful();

        $vm->refresh();
        $this->assertTrue($vm->status->equals(Provisioning::class));

        // Custom cutoff of 30 seconds sweeps it
        $this->artisan('vm:sweep-stale-provisioning', ['--max-age-seconds' => 30])
            ->expectsOutputToContain('Swept 1 stale provisioning VM(s).')
            ->assertSuccessful();

        $vm->refresh();
        $this->assertTrue($vm->status->equals(Failed::class));
    }

    public function test_sweeper_is_scheduled_every_five_minutes_without_overlapping(): void
    {
        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);

        $event = collect($schedule->events())->first(
            fn (Event $event): bool => is_string($event->command) && str_contains($event->command, 'vm:sweep-stale-provisioning')
        );

        $this->assertNotNull($event, 'The vm:sweep-stale-provisioning command is not scheduled.');
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
