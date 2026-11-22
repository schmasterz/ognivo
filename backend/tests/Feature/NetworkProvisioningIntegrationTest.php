<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\IpPoolExhaustedException;
use App\Models\Host;
use App\Models\Image;
use App\Models\IpAllocation;
use App\Models\IpPool;
use App\Models\User;
use App\Models\UserQuota;
use App\Models\VirtualMachine;
use App\Services\Hypervisor\Drivers\FakeHypervisorDriver;
use App\Services\Hypervisor\HypervisorManager;
use App\Services\Hypervisor\InstanceSpecBuilder;
use App\Services\IpManagementService;
use App\Services\VmProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class NetworkProvisioningIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $tenant;

    private Host $host;

    private Image $image;

    private FakeHypervisorDriver $fakeDriver;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.allow_fake_driver' => true]);

        $this->fakeDriver = new FakeHypervisorDriver;
        HypervisorManager::fake($this->fakeDriver);

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $this->admin = User::factory()->create(['name' => 'Admin User', 'email' => 'admin-net@test.local']);
        $this->admin->assignRole('admin');

        $this->tenant = User::factory()->create(['name' => 'Tenant User', 'email' => 'tenant-net@test.local']);
        $this->tenant->assignRole('customer');

        UserQuota::create([
            'user_id' => $this->tenant->id,
            'max_vms' => 10,
            'max_cpu_cores' => 32,
            'max_ram_mb' => 65536,
            'max_storage_gb' => 1000,
            'max_ips' => 10,
        ]);

        $this->host = Host::create([
            'name' => 'sofia-edge-node',
            'endpoint_url' => 'https://127.0.0.1:8443',
            'driver' => 'fake',
            'is_active' => true,
            'total_cpu_cores' => 64,
            'total_ram_mb' => 131072,
            'total_storage_gb' => 4000,
            'capacity_source' => 'discovered',
            'capacity_synced_at' => now(),
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

    public function test_full_routed_network_provisioning_lifecycle(): void
    {
        // 1. Create a /29 routed IP pool via API
        Sanctum::actingAs($this->admin);

        $poolRes = $this->postJson('/api/admin/ip-pools', [
            'name' => 'Sofia DMZ /29 Pool',
            'host_id' => $this->host->id,
            'cidr' => '203.0.113.64/29', // Network .64, Gateway .65, Usable .66 to .70 (5 usable addresses)
            'first_usable' => '203.0.113.66',
            'last_usable' => '203.0.113.70',
            'gateway' => '203.0.113.65',
            'parent_interface' => 'eth0',
            'network_mode' => 'routed',
            'cooldown_seconds' => 300,
        ]);

        $poolRes->assertStatus(201);
        $poolId = (int) $poolRes->json('pool.id');
        $pool = IpPool::findOrFail($poolId);

        // Verification: Ranged model does NOT generate empty pre-materialized database rows!
        $this->assertEquals(0, $pool->allocations()->count(), 'New ranged pool should have 0 allocation rows before provisioning.');

        // 2. Provision VMs sequentially up to capacity
        Sanctum::actingAs($this->tenant);
        /** @var VmProvisioningService $provService */
        $provService = app(VmProvisioningService::class);

        /** @var list<VirtualMachine> $vms */
        $vms = [];
        for ($i = 1; $i <= 5; $i++) {
            $vm = $provService->provision($this->tenant, [
                'name' => "srv-app-{$i}",
                'cpu_cores' => 1,
                'ram_mb' => 1024,
                'disk_gb' => 10,
                'os_image' => 'ubuntu/24.04',
                'host_id' => $this->host->id,
            ]);
            $vms[] = $vm;
        }

        // Each VM must have a unique allocated IP in range .66 to .70
        $allocatedIps = array_map(fn (VirtualMachine $v) => $v->ipv4_address, $vms);
        $this->assertCount(5, array_unique($allocatedIps), 'All 5 allocated IPs must be unique.');
        $this->assertEquals(['203.0.113.66', '203.0.113.67', '203.0.113.68', '203.0.113.69', '203.0.113.70'], $allocatedIps);

        // 3. Verify Incus Instance Spec Builder output
        /** @var InstanceSpecBuilder $builder */
        $builder = app(InstanceSpecBuilder::class);
        $spec = $builder->build($vms[0]);

        $this->assertEquals('virtual-machine', $spec['type']);
        $this->assertArrayHasKey('devices', $spec);
        $this->assertArrayHasKey('eth0', $spec['devices']);
        $this->assertEquals('nic', $spec['devices']['eth0']['type']);
        $this->assertEquals('routed', $spec['devices']['eth0']['nictype']);
        $this->assertEquals('eth0', $spec['devices']['eth0']['parent']);
        $this->assertStringStartsWith('02:54:', (string) $spec['devices']['eth0']['hwaddr']);
        $this->assertEquals('203.0.113.66', $spec['devices']['eth0']['ipv4.address']);

        // Verify Netplan v2 in cloud-init network-config
        $networkConfig = (string) $spec['config']['cloud-init.network-config'];
        $this->assertStringContainsString('version: 2', $networkConfig);
        $this->assertStringContainsString('to: 0.0.0.0/0', $networkConfig);
        $this->assertStringContainsString('via: 169.254.0.1', $networkConfig);
        $this->assertStringContainsString('on-link: true', $networkConfig);
        $this->assertStringContainsString('203.0.113.66/32', $networkConfig);

        // 4. Test Pool Exhaustion: 6th VM attempt must fail with ValidationException / 422
        try {
            $provService->provision($this->tenant, [
                'name' => 'srv-app-overflow',
                'cpu_cores' => 1,
                'ram_mb' => 1024,
                'disk_gb' => 10,
                'os_image' => 'ubuntu/24.04',
                'host_id' => $this->host->id,
            ]);
            $this->fail('Expected ValidationException was not thrown on capacity overflow.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('No available IP addresses in pool', $e->getMessage());
        }

        // 5. Lifecycle & Cooling: Release IP of VM #1
        /** @var IpManagementService $ipService */
        $ipService = app(IpManagementService::class);
        $firstAllocation = IpAllocation::where('ip_string', '203.0.113.66')->firstOrFail();

        $ipService->emergencyReleaseAllocation($firstAllocation);

        $firstAllocation->refresh();
        $this->assertEquals('cooling', $firstAllocation->status);
        $this->assertNotNull($firstAllocation->released_at);
        $this->assertNull($firstAllocation->virtual_machine_id);

        // Immediate allocation attempt must NOT reuse .66 while in cooling quarantine!
        try {
            $ipService->allocateIp($pool, $vms[0], $this->tenant);
            $this->fail('Should not allocate an IP when only cooling address exists.');
        } catch (IpPoolExhaustedException) {
            // Success: cooling IP was properly quarantined!
        }

        // 6. Fast-forward past cooldown period (300 seconds)
        $this->travel(305)->seconds();

        // Now .66 is eligible for reuse without duplicate key collision!
        $reusedAlloc = $ipService->allocateIp($pool, $vms[0], $this->tenant);
        $this->assertEquals('203.0.113.66', $reusedAlloc->ip_string);
        $this->assertEquals('allocated', $reusedAlloc->status);
        $this->assertEquals($vms[0]->id, $reusedAlloc->virtual_machine_id);

        // Total allocation rows in DB remains 5 (no unbounded growth)
        $this->assertEquals(5, $pool->allocations()->count());
    }
}
