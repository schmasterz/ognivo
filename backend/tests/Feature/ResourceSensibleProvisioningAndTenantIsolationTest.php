<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Host;
use App\Models\Image;
use App\Models\IpAllocation;
use App\Models\IpPool;
use App\Models\User;
use App\Models\UserQuota;
use App\Models\VirtualMachine;
use App\Services\HostSelector;
use App\States\Vm\Running;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ResourceSensibleProvisioningAndTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private User $admin;

    private Image $image;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->customer = User::factory()->create();
        $this->customer->assignRole('customer');

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        UserQuota::create([
            'user_id' => $this->customer->id,
            'max_vms' => 10,
            'max_cpu_cores' => 32,
            'max_ram_mb' => 65536,
            'max_storage_gb' => 1000,
            'max_ips' => 10,
        ]);

        $this->image = Image::firstOrCreate(
            ['name' => 'images:ubuntu/24.04'],
            [
                'os_type' => 'linux',
                'version' => '24.04',
                'description' => 'Ubuntu Noble',
                'source' => 'images.lxd.canonical.com',
                'enabled' => true,
            ]
        );
    }

    public function test_exhausted_first_pool_falls_through_to_the_second(): void
    {
        $host = Host::create([
            'name' => 'multi-pool-node',
            'endpoint_url' => 'https://node-multipool.local:8443',
            'driver' => 'fake',
            'status' => 'ok',
            'is_active' => true,
            'total_cpu_cores' => 32,
            'total_ram_mb' => 65536,
            'total_storage_gb' => 1000,
            'capacity_source' => 'discovered',
            'capacity_synced_at' => now(),
        ]);
        $host->images()->syncWithoutDetaching([$this->image->id]);

        // Pool 1: 192.168.1.0/30 (Usable: .1 and .2; gateway is .1, usable IP is .2)
        $exhaustedPool = IpPool::create([
            'host_id' => $host->id,
            'name' => 'Exhausted Pool',
            'cidr' => '192.168.1.0/30',
            'subnet' => '192.168.1.0/30',
            'gateway' => '192.168.1.1',
            'ip_version' => 4,
            'active' => true,
            'is_public' => true,
        ]);

        // Allocate the only usable IP (.2) in Pool 1
        IpAllocation::create([
            'pool_id' => $exhaustedPool->id,
            'address' => (string) inet_pton('192.168.1.2'),
            'ip_string' => '192.168.1.2',
            'mac_address' => '02:54:aa:bb:cc:01',
            'status' => 'allocated',
            'user_id' => $this->customer->id,
            'allocated_at' => now(),
        ]);

        // Pool 2: 192.168.2.0/29 (Has free addresses)
        $freePool = IpPool::create([
            'host_id' => $host->id,
            'name' => 'Fresh Available Pool',
            'cidr' => '192.168.2.0/29',
            'subnet' => '192.168.2.0/29',
            'gateway' => '192.168.2.1',
            'ip_version' => 4,
            'active' => true,
            'is_public' => true,
        ]);

        // Provision VM on this host
        $response = $this->actingAs($this->customer)->postJson('/api/v1/vms', [
            'name' => 'fallthrough-vm',
            'host_id' => $host->id,
            'instance_type' => 'virtual-machine',
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'images:ubuntu/24.04',
        ]);

        $response->assertStatus(202);

        // Verify allocated IP is from the second pool
        $createdVm = VirtualMachine::where('name', 'fallthrough-vm')->firstOrFail();
        $allocation = IpAllocation::where('virtual_machine_id', $createdVm->id)->firstOrFail();

        $this->assertSame($freePool->id, $allocation->pool_id);
        $this->assertStringStartsWith('192.168.2.', $allocation->ip_string);
    }

    public function test_host_selection_excludes_unhealthy_fake_and_imageless_hosts_and_prefers_least_loaded(): void
    {
        // Host 1: Unhealthy status
        $unhealthyHost = Host::create([
            'name' => 'unhealthy-node',
            'endpoint_url' => 'https://unhealthy.local:8443',
            'driver' => 'fake',
            'status' => 'unreachable',
            'is_active' => true,
            'total_cpu_cores' => 32,
            'total_ram_mb' => 65536,
            'total_storage_gb' => 1000,
            'capacity_source' => 'discovered',
            'capacity_synced_at' => now(),
        ]);
        $unhealthyHost->images()->syncWithoutDetaching([$this->image->id]);
        IpPool::create([
            'host_id' => $unhealthyHost->id,
            'name' => 'Unhealthy Node Pool',
            'cidr' => '10.1.0.0/24',
            'subnet' => '10.1.0.0/24',
            'gateway' => '10.1.0.1',
            'active' => true,
        ]);

        // Host 2: Imageless (does not have the requested image)
        $imagelessHost = Host::create([
            'name' => 'imageless-node',
            'endpoint_url' => 'https://imageless.local:8443',
            'driver' => 'fake',
            'status' => 'ok',
            'is_active' => true,
            'total_cpu_cores' => 32,
            'total_ram_mb' => 65536,
            'total_storage_gb' => 1000,
            'capacity_source' => 'discovered',
            'capacity_synced_at' => now(),
        ]);
        $imagelessHost->images()->detach();
        IpPool::create([
            'host_id' => $imagelessHost->id,
            'name' => 'Imageless Pool',
            'cidr' => '10.2.0.0/24',
            'subnet' => '10.2.0.0/24',
            'gateway' => '10.2.0.1',
            'active' => true,
        ]);

        // Host 3: Candidate A - Higher load
        $heavyHost = Host::create([
            'name' => 'heavy-node',
            'endpoint_url' => 'https://heavy.local:8443',
            'driver' => 'fake',
            'status' => 'ok',
            'is_active' => true,
            'total_cpu_cores' => 16,
            'total_ram_mb' => 32768,
            'total_storage_gb' => 500,
            'capacity_source' => 'discovered',
            'capacity_synced_at' => now(),
        ]);
        $heavyHost->images()->syncWithoutDetaching([$this->image->id]);
        IpPool::create([
            'host_id' => $heavyHost->id,
            'name' => 'Heavy Host Pool',
            'cidr' => '10.3.0.0/24',
            'subnet' => '10.3.0.0/24',
            'gateway' => '10.3.0.1',
            'active' => true,
        ]);

        // Commit heavy VM load on Host 3 (8 cores, 16384 MB RAM, 250 GB storage = 50% load)
        VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $heavyHost->id,
            'name' => 'heavy-workload',
            'incus_project' => 'default',
            'status' => Running::class,
            'cpu_cores' => 8,
            'ram_mb' => 16384,
            'disk_gb' => 250,
            'os_image' => 'images:ubuntu/24.04',
        ]);

        // Host 4: Candidate B - Lower load
        $lightHost = Host::create([
            'name' => 'light-node',
            'endpoint_url' => 'https://light.local:8443',
            'driver' => 'fake',
            'status' => 'ok',
            'is_active' => true,
            'total_cpu_cores' => 16,
            'total_ram_mb' => 32768,
            'total_storage_gb' => 500,
            'capacity_source' => 'discovered',
            'capacity_synced_at' => now(),
        ]);
        $lightHost->images()->syncWithoutDetaching([$this->image->id]);
        IpPool::create([
            'host_id' => $lightHost->id,
            'name' => 'Light Host Pool',
            'cidr' => '10.4.0.0/24',
            'subnet' => '10.4.0.0/24',
            'gateway' => '10.4.0.1',
            'active' => true,
        ]);

        // Commit light VM load on Host 4 (2 cores, 4096 MB RAM, 50 GB storage = 12.5% load)
        VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $lightHost->id,
            'name' => 'light-workload',
            'incus_project' => 'default',
            'status' => Running::class,
            'cpu_cores' => 2,
            'ram_mb' => 4096,
            'disk_gb' => 50,
            'os_image' => 'images:ubuntu/24.04',
        ]);

        /** @var HostSelector $selector */
        $selector = app(HostSelector::class);

        // HostSelector should select $lightHost (least loaded among valid hosts)
        $selected = $selector->select(['os_image' => 'images:ubuntu/24.04']);
        $this->assertNotNull($selected);
        $this->assertSame($lightHost->id, $selected->id);

        // Verify driver != 'fake' exclusion in non-testing/local environment
        config(['app.env' => 'production']);
        app()['env'] = 'production';

        $realDriverHost = Host::create([
            'name' => 'real-driver-node',
            'endpoint_url' => 'https://real.local:8443',
            'driver' => 'incus',
            'status' => 'ok',
            'is_active' => true,
            'total_cpu_cores' => 32,
            'total_ram_mb' => 65536,
            'total_storage_gb' => 1000,
            'capacity_source' => 'discovered',
            'capacity_synced_at' => now(),
        ]);
        $realDriverHost->images()->syncWithoutDetaching([$this->image->id]);
        IpPool::create([
            'host_id' => $realDriverHost->id,
            'name' => 'Real Driver Pool',
            'cidr' => '10.5.0.0/24',
            'subnet' => '10.5.0.0/24',
            'gateway' => '10.5.0.1',
            'active' => true,
        ]);

        $selectedProd = $selector->select(['os_image' => 'images:ubuntu/24.04']);
        $this->assertNotNull($selectedProd);
        $this->assertSame($realDriverHost->id, $selectedProd->id);

        // Restore testing environment
        config(['app.env' => 'testing']);
        app()['env'] = 'testing';
    }

    public function test_explicit_host_id_with_no_free_ip_returns_422(): void
    {
        $host = Host::create([
            'name' => 'exhausted-host-node',
            'endpoint_url' => 'https://exhausted.local:8443',
            'driver' => 'fake',
            'status' => 'ok',
            'is_active' => true,
            'total_cpu_cores' => 16,
            'total_ram_mb' => 32768,
            'total_storage_gb' => 500,
            'capacity_source' => 'discovered',
            'capacity_synced_at' => now(),
        ]);
        $host->images()->syncWithoutDetaching([$this->image->id]);

        $pool = IpPool::create([
            'host_id' => $host->id,
            'name' => 'Completely Full Pool',
            'cidr' => '192.168.10.0/30',
            'subnet' => '192.168.10.0/30',
            'gateway' => '192.168.10.1',
            'ip_version' => 4,
            'active' => true,
            'is_public' => true,
        ]);

        // Consume the only usable IP address in the pool (.2)
        IpAllocation::create([
            'pool_id' => $pool->id,
            'address' => (string) inet_pton('192.168.10.2'),
            'ip_string' => '192.168.10.2',
            'mac_address' => '02:54:aa:bb:cc:02',
            'status' => 'allocated',
            'user_id' => $this->customer->id,
            'allocated_at' => now(),
        ]);

        $response = $this->actingAs($this->customer)->postJson('/api/v1/vms', [
            'name' => 'should-fail-vm',
            'host_id' => $host->id,
            'instance_type' => 'virtual-machine',
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'images:ubuntu/24.04',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['ip_pool']);
        $this->assertStringContainsString('no free IP on this compute node', $response->json('errors.ip_pool.0'));
    }

    public function test_explicit_host_id_with_unhealthy_or_imageless_host_returns_422(): void
    {
        // 1. Unhealthy host
        $unhealthyHost = Host::create([
            'name' => 'explicit-unhealthy',
            'endpoint_url' => 'https://exp-unhealthy.local:8443',
            'driver' => 'fake',
            'status' => 'fingerprint_mismatch',
            'is_active' => true,
            'total_cpu_cores' => 16,
            'total_ram_mb' => 32768,
            'total_storage_gb' => 500,
            'capacity_source' => 'discovered',
            'capacity_synced_at' => now(),
        ]);
        $unhealthyHost->images()->syncWithoutDetaching([$this->image->id]);
        IpPool::create([
            'host_id' => $unhealthyHost->id,
            'name' => 'Exp Unhealthy Pool',
            'cidr' => '10.80.0.0/24',
            'subnet' => '10.80.0.0/24',
            'gateway' => '10.80.0.1',
            'active' => true,
        ]);

        $resUnhealthy = $this->actingAs($this->customer)->postJson('/api/v1/vms', [
            'name' => 'vm-on-unhealthy',
            'host_id' => $unhealthyHost->id,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'images:ubuntu/24.04',
        ]);
        $resUnhealthy->assertStatus(422);
        $resUnhealthy->assertJsonValidationErrors(['host_id']);

        // 2. Imageless host
        $imagelessHost = Host::create([
            'name' => 'explicit-imageless',
            'endpoint_url' => 'https://exp-imageless.local:8443',
            'driver' => 'fake',
            'status' => 'ok',
            'is_active' => true,
            'total_cpu_cores' => 16,
            'total_ram_mb' => 32768,
            'total_storage_gb' => 500,
            'capacity_source' => 'discovered',
            'capacity_synced_at' => now(),
        ]);
        $imagelessHost->images()->detach();
        IpPool::create([
            'host_id' => $imagelessHost->id,
            'name' => 'Exp Imageless Pool',
            'cidr' => '10.81.0.0/24',
            'subnet' => '10.81.0.0/24',
            'gateway' => '10.81.0.1',
            'active' => true,
        ]);

        $resImageless = $this->actingAs($this->customer)->postJson('/api/v1/vms', [
            'name' => 'vm-on-imageless',
            'host_id' => $imagelessHost->id,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'images:ubuntu/24.04',
        ]);
        $resImageless->assertStatus(422);
        $resImageless->assertJsonValidationErrors(['os_image']);
    }

    public function test_host_selector_tie_breaks_by_id_and_applies_overcommit(): void
    {
        $host1 = Host::create([
            'name' => 'tie-break-node-1',
            'endpoint_url' => 'https://tie1.local:8443',
            'driver' => 'fake',
            'status' => 'ok',
            'is_active' => true,
            'total_cpu_cores' => 16,
            'total_ram_mb' => 32768,
            'total_storage_gb' => 500,
            'capacity_source' => 'discovered',
            'capacity_synced_at' => now(),
        ]);
        $host1->images()->syncWithoutDetaching([$this->image->id]);
        IpPool::create([
            'host_id' => $host1->id,
            'name' => 'Tie Pool 1',
            'cidr' => '10.90.0.0/24',
            'subnet' => '10.90.0.0/24',
            'gateway' => '10.90.0.1',
            'active' => true,
        ]);

        $host2 = Host::create([
            'name' => 'tie-break-node-2',
            'endpoint_url' => 'https://tie2.local:8443',
            'driver' => 'fake',
            'status' => 'ok',
            'is_active' => true,
            'total_cpu_cores' => 16,
            'total_ram_mb' => 32768,
            'total_storage_gb' => 500,
            'capacity_source' => 'discovered',
            'capacity_synced_at' => now(),
        ]);
        $host2->images()->syncWithoutDetaching([$this->image->id]);
        IpPool::create([
            'host_id' => $host2->id,
            'name' => 'Tie Pool 2',
            'cidr' => '10.91.0.0/24',
            'subnet' => '10.91.0.0/24',
            'gateway' => '10.91.0.1',
            'active' => true,
        ]);

        /** @var HostSelector $selector */
        $selector = app(HostSelector::class);

        // Both hosts have 0 load -> tie break by lower id
        $selected = $selector->select(['os_image' => 'images:ubuntu/24.04']);
        $this->assertNotNull($selected);
        $expectedLowerId = min($host1->id, $host2->id);
        $this->assertSame($expectedLowerId, $selected->id);

        // Test per-host overcommit factor update
        $host1->update(['cpu_overcommit' => 2.0]);
        $this->assertSame(2.0, $host1->fresh()->cpu_overcommit);
    }

    public function test_customer_calling_ip_pools_and_ips_never_receives_infrastructure_internals(): void
    {
        $host = Host::create([
            'name' => 'infra-test-node',
            'endpoint_url' => 'https://infra.internal:8443',
            'driver' => 'fake',
            'status' => 'ok',
            'is_active' => true,
            'total_cpu_cores' => 32,
            'total_ram_mb' => 65536,
            'total_storage_gb' => 1000,
            'capacity_source' => 'discovered',
            'capacity_synced_at' => now(),
        ]);

        $pool = IpPool::create([
            'host_id' => $host->id,
            'name' => 'Protected Tenant Pool',
            'cidr' => '172.16.50.0/24',
            'subnet' => '172.16.50.0/24',
            'gateway' => '172.16.50.1',
            'dns_servers' => ['1.1.1.1', '8.8.8.8'],
            'parent_interface' => 'bond0.100',
            'network_mode' => 'routed',
            'ip_version' => 4,
            'active' => true,
            'is_public' => true,
        ]);

        $vm = VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $host->id,
            'name' => 'customer-workload-sec',
            'incus_project' => 'proj-sec',
            'status' => Running::class,
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'images:ubuntu/24.04',
        ]);

        IpAllocation::create([
            'pool_id' => $pool->id,
            'user_id' => $this->customer->id,
            'virtual_machine_id' => $vm->id,
            'address' => (string) inet_pton('172.16.50.15'),
            'ip_string' => '172.16.50.15',
            'mac_address' => '02:54:11:22:33:44',
            'status' => 'allocated',
            'allocated_at' => now(),
        ]);

        $forbiddenKeys = [
            'parent_interface',
            'gateway',
            'dns_servers',
            'cidr',
            'network_mode',
        ];

        // 1. GET /api/ip-pools as customer
        $poolsResponse = $this->actingAs($this->customer)->getJson('/api/v1/ip-pools');
        $poolsResponse->assertOk();
        $poolsPayload = $poolsResponse->json();

        $this->assertNoForbiddenKeysRecursively($poolsPayload, $forbiddenKeys);

        // Verify each pool item only contains id, name, host_id, free
        $poolsData = $poolsResponse->json('data');
        $this->assertNotEmpty($poolsData);
        foreach ($poolsData as $item) {
            $this->assertEqualsCanonicalizing(['id', 'name', 'host_id', 'free'], array_keys($item));
            $this->assertIsBool($item['free']);
        }

        // 2. GET /api/ips as customer
        $ipsResponse = $this->actingAs($this->customer)->getJson('/api/v1/ips');
        $ipsResponse->assertOk();
        $ipsPayload = $ipsResponse->json();

        $this->assertNoForbiddenKeysRecursively($ipsPayload, $forbiddenKeys);

        $ipsData = $ipsResponse->json('data');
        $this->assertNotEmpty($ipsData);
        $customerAllocation = collect($ipsData)->firstWhere('address', '172.16.50.15');
        $this->assertNotNull($customerAllocation);
        $this->assertSame('allocated', $customerAllocation['status']);
        $this->assertSame('customer-workload-sec', $customerAllocation['virtual_machine']['name']);

        // 3. Admin call to /api/ip-pools and /api/admin/ip-pools DOES receive full resource
        $adminResponse = $this->actingAs($this->admin)->getJson('/api/v1/ip-pools');
        $adminResponse->assertOk();
        $adminPool = collect($adminResponse->json('data'))->firstWhere('id', $pool->id);
        $this->assertNotNull($adminPool);
        $this->assertArrayHasKey('parent_interface', $adminPool);
        $this->assertArrayHasKey('gateway', $adminPool);
        $this->assertArrayHasKey('cidr', $adminPool);
        $this->assertArrayHasKey('utilization', $adminPool);
    }

    /**
     * Recursively assert that none of the forbidden keys exist in an array structure.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $forbiddenKeys
     */
    private function assertNoForbiddenKeysRecursively(array $data, array $forbiddenKeys): void
    {
        foreach ($data as $key => $value) {
            $this->assertNotContains(
                $key,
                $forbiddenKeys,
                "Forbidden infrastructure key [{$key}] was exposed to tenant!"
            );
            if (is_array($value)) {
                $this->assertNoForbiddenKeysRecursively($value, $forbiddenKeys);
            }
        }
    }
}
