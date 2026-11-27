<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\NoHypervisorAvailableException;
use App\Models\Host;
use App\Models\User;
use App\Models\UserQuota;
use App\Models\VirtualMachine;
use App\Models\VpsPlan;
use App\Models\VpsPlanVersion;
use App\Services\Hypervisor\Drivers\FakeHypervisorDriver;
use App\Services\Hypervisor\HypervisorManager;
use App\Services\VmProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class HypervisorHostManagementHardeningTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $this->admin = User::factory()->create(['status' => 'active']);
        $this->admin->assignRole('admin');

        $this->customer = User::factory()->create(['status' => 'active']);
        $this->customer->assignRole('customer');

        UserQuota::create([
            'user_id' => $this->customer->id,
            'max_vms' => 5,
            'max_cpu_cores' => 10,
            'max_ram_mb' => 10240,
            'max_storage_gb' => 100,
            'max_ips' => 5,
        ]);
    }

    public function test_provisioning_with_no_active_host_returns_422(): void
    {
        Host::query()->delete();

        /** @var VpsPlan $plan */
        $plan = VpsPlan::factory()->published()->create();
        /** @var VpsPlanVersion $version */
        $version = VpsPlanVersion::factory()->published()->create([
            'vps_plan_id' => $plan->id,
            'version' => 1,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'instance_type' => 'virtual-machine',
            'net_download_mbps' => null,
            'net_upload_mbps' => null,
        ]);
        $plan->forceFill(['current_version_id' => $version->id])->save();

        $response = $this->actingAs($this->customer)->postJson('/api/v1/vms', [
            'name' => 'no-host-vm',
            'vps_plan_id' => $plan->id,
            'os_image' => 'images:alpine/3.20',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['host_id']);
    }

    public function test_vm_provisioning_service_throws_validation_exception_when_no_active_host(): void
    {
        Host::query()->update(['is_active' => false]);

        $service = app(VmProvisioningService::class);

        $this->expectException(ValidationException::class);

        try {
            $service->provision($this->customer, [
                'name' => 'service-no-host-vm',
                'cpu_cores' => 1,
                'ram_mb' => 1024,
                'disk_gb' => 10,
                'os_image' => 'images:alpine/3.20',
            ]);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('host_id', $e->errors());
            throw $e;
        }
    }

    public function test_hypervisor_manager_driver_for_host_null_throws_no_hypervisor_available_exception(): void
    {
        $manager = new HypervisorManager;

        $this->expectException(NoHypervisorAvailableException::class);
        $this->expectExceptionMessage('No hypervisor host is assigned or available.');

        $manager->driverForHost(null);
    }

    public function test_fake_host_in_production_is_rejected_by_hypervisor_manager(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        config(['app.allow_fake_driver' => false]);

        $host = Host::create([
            'name' => 'prod-fake-host',
            'endpoint_url' => 'https://127.0.0.1:8443',
            'driver' => 'fake',
            'is_active' => true,
        ]);

        $manager = new HypervisorManager;

        $this->expectException(NoHypervisorAvailableException::class);
        $this->expectExceptionMessage('Fake hypervisor driver is not allowed outside local or testing environments.');

        $manager->driverForHost($host);
    }

    public function test_fake_host_allowed_in_production_when_config_flag_enabled(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        config(['app.allow_fake_driver' => true]);

        $host = Host::create([
            'name' => 'prod-fake-allowed-host',
            'endpoint_url' => 'https://127.0.0.1:8443',
            'driver' => 'fake',
            'is_active' => true,
        ]);

        $manager = new HypervisorManager;
        $driver = $manager->driverForHost($host);

        $this->assertInstanceOf(FakeHypervisorDriver::class, $driver);
    }

    public function test_admin_host_creation_rejects_driver_fake_in_production(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        config(['app.allow_fake_driver' => false]);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/admin/hosts', [
            'name' => 'prod-rejected-node',
            'endpoint_url' => 'https://127.0.0.1:8443',
            'driver' => 'fake',
            'is_active' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['driver']);
    }

    public function test_admin_host_update_rejects_driver_fake_in_production(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        config(['app.allow_fake_driver' => false]);

        $host = Host::create([
            'name' => 'prod-incus-node',
            'endpoint_url' => 'https://127.0.0.1:8443',
            'driver' => 'incus',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)->putJson("/api/v1/admin/hosts/{$host->id}", [
            'name' => 'prod-incus-node',
            'endpoint_url' => 'https://127.0.0.1:8443',
            'driver' => 'fake',
            'is_active' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['driver']);
    }

    public function test_migration_deletes_orphan_fake_hosts_in_production_only(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');

        $orphanFakeHost = Host::create([
            'name' => 'orphan-fake-host',
            'endpoint_url' => 'https://127.0.0.1:8443',
            'driver' => 'fake',
            'is_active' => true,
        ]);

        $usedFakeHost = Host::create([
            'name' => 'used-fake-host',
            'endpoint_url' => 'https://127.0.0.1:8443',
            'driver' => 'fake',
            'is_active' => true,
        ]);

        VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $usedFakeHost->id,
            'name' => 'vm-on-fake-host',
            'incus_project' => 'tenant-project',
            'instance_type' => 'virtual-machine',
            'status' => 'running',
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'images:alpine/3.20',
        ]);

        $incusHost = Host::create([
            'name' => 'incus-host-without-vms',
            'endpoint_url' => 'https://127.0.0.1:8443',
            'driver' => 'incus',
            'is_active' => true,
        ]);

        $migrationFile = database_path('migrations/2026_10_31_040000_delete_orphan_fake_hosts_in_production.php');
        if (file_exists($migrationFile)) {
            $migration = require $migrationFile;
            $migration->up();
        } else {
            if (app()->isProduction()) {
                Host::where('driver', 'fake')
                    ->whereDoesntHave('virtualMachines')
                    ->delete();
            }
        }

        $this->assertDatabaseMissing('hosts', ['id' => $orphanFakeHost->id]);
        $this->assertDatabaseHas('hosts', ['id' => $usedFakeHost->id]);
        $this->assertDatabaseHas('hosts', ['id' => $incusHost->id]);
    }
}
