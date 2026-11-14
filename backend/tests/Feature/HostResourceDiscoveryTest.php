<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTO\Host\UpdateHostDTO;
use App\Models\Host;
use App\Models\User;
use App\Services\HostNodeService;
use App\Services\Hypervisor\Drivers\FakeHypervisorDriver;
use App\Services\Hypervisor\HypervisorManager;
use App\Services\IncusTrustService;
use App\Services\PanelCertificateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class HostResourceDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    protected function tearDown(): void
    {
        HypervisorManager::resetFake();
        parent::tearDown();
    }

    public function test_driver_parses_realistic_incus_resources_responses(): void
    {
        app(PanelCertificateService::class)->generate(365);

        $host = Host::create([
            'name' => 'incus-discovery-node',
            'endpoint_url' => 'https://192.168.10.50:8443',
            'driver' => 'incus',
            'server_fingerprint' => 'test-fp',
            'server_public_key_pin' => 'pin-sha256:test',
            'capacity_source' => 'unknown',
            'is_active' => true,
        ]);

        $mockResourcesJson = [
            'type' => 'sync',
            'status' => 'Success',
            'status_code' => 200,
            'metadata' => [
                'cpu' => [
                    'total' => 16,
                    'sockets' => [
                        [
                            'name' => 'CPU 0',
                            'threads' => 16,
                        ],
                    ],
                ],
                'memory' => [
                    'total' => 34359738368, // 32 GB = 32768 MB
                    'used' => 8589934592,
                ],
            ],
        ];

        $mockStoragePoolsJson = [
            'type' => 'sync',
            'status' => 'Success',
            'status_code' => 200,
            'metadata' => [
                '/1.0/storage-pools/default',
            ],
        ];

        $mockStorageResourcesJson = [
            'type' => 'sync',
            'status' => 'Success',
            'status_code' => 200,
            'metadata' => [
                'space' => [
                    'total' => 1073741824000, // 1000 GB
                    'used' => 214748364800,
                ],
            ],
        ];

        Http::fake([
            'https://192.168.10.50:8443/1.0/resources' => Http::response($mockResourcesJson, 200),
            'https://192.168.10.50:8443/1.0/storage-pools' => Http::response($mockStoragePoolsJson, 200),
            'https://192.168.10.50:8443/1.0/storage-pools/default/resources' => Http::response($mockStorageResourcesJson, 200),
        ]);

        /** @var HostNodeService $service */
        $service = app(HostNodeService::class);
        $updatedHost = $service->syncHostResources($host);

        $this->assertSame(16, $updatedHost->total_cpu_cores);
        $this->assertSame(32768, $updatedHost->total_ram_mb);
        $this->assertSame(1000, $updatedHost->total_storage_gb);
        $this->assertSame('discovered', $updatedHost->capacity_source);
        $this->assertNotNull($updatedHost->capacity_synced_at);
    }

    public function test_onboarding_discovery_failure_leaves_totals_at_zero_and_unknown(): void
    {
        $fakeDriver = new FakeHypervisorDriver;
        $fakeDriver->shouldFail['getHostResources'] = true;
        HypervisorManager::fake($fakeDriver);

        $host = Host::create([
            'name' => 'failing-host',
            'endpoint_url' => 'https://10.99.99.1:8443',
            'driver' => 'fake',
            'total_cpu_cores' => 0,
            'total_ram_mb' => 0,
            'total_storage_gb' => 0,
            'capacity_source' => 'unknown',
        ]);

        /** @var IncusTrustService $trustService */
        $trustService = app(IncusTrustService::class);
        $trustService->discoverAndSetHostCapacity($host);

        $host->refresh();
        $this->assertSame(0, $host->total_cpu_cores);
        $this->assertSame(0, $host->total_ram_mb);
        $this->assertSame(0, $host->total_storage_gb);
        $this->assertSame('unknown', $host->capacity_source);
        $this->assertNull($host->capacity_synced_at);
    }

    public function test_sync_never_overwrites_manual_capacity(): void
    {
        $fakeDriver = new FakeHypervisorDriver;
        HypervisorManager::fake($fakeDriver);

        $host = Host::create([
            'name' => 'manual-capacity-host',
            'endpoint_url' => 'https://10.99.99.2:8443',
            'driver' => 'fake',
            'total_cpu_cores' => 128,
            'total_ram_mb' => 524288,
            'total_storage_gb' => 10000,
            'capacity_source' => 'manual',
        ]);

        $this->artisan('hosts:sync-resources', ['host' => (string) $host->id])
            ->expectsOutputToContain('manual')
            ->assertSuccessful();

        $host->refresh();
        $this->assertSame(128, $host->total_cpu_cores);
        $this->assertSame(524288, $host->total_ram_mb);
        $this->assertSame(10000, $host->total_storage_gb);
        $this->assertSame('manual', $host->capacity_source);
    }

    public function test_manual_edit_flips_capacity_source_to_manual(): void
    {
        $host = Host::create([
            'name' => 'discovered-host',
            'endpoint_url' => 'https://10.99.99.3:8443',
            'driver' => 'fake',
            'total_cpu_cores' => 16,
            'total_ram_mb' => 32768,
            'total_storage_gb' => 500,
            'capacity_source' => 'discovered',
        ]);

        /** @var HostNodeService $service */
        $service = app(HostNodeService::class);
        $service->update($host, new UpdateHostDTO(
            name: 'discovered-host-renamed',
            endpointUrl: $host->endpoint_url,
            totalCpuCores: 24,
        ));

        $host->refresh();
        $this->assertSame(24, $host->total_cpu_cores);
        $this->assertSame('manual', $host->capacity_source);
    }

    public function test_admin_api_endpoint_sync_resources(): void
    {
        $fakeDriver = new FakeHypervisorDriver;
        HypervisorManager::fake($fakeDriver);

        $host = Host::create([
            'name' => 'api-sync-host',
            'endpoint_url' => 'https://10.99.99.4:8443',
            'driver' => 'fake',
            'total_cpu_cores' => 0,
            'total_ram_mb' => 0,
            'total_storage_gb' => 0,
            'capacity_source' => 'unknown',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/hosts/{$host->id}/sync-resources");

        $response->assertOk()
            ->assertJsonPath('host.capacity.source', 'discovered')
            ->assertJsonPath('host.total_cpu_cores', 32)
            ->assertJsonPath('host.total_ram_mb', 65536)
            ->assertJsonPath('host.total_storage_gb', 2000);

        $host->refresh();
        $this->assertSame('discovered', $host->capacity_source);
    }
}
