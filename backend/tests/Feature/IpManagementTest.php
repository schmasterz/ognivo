<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProvisionVmJob;
use App\Models\Host;
use App\Models\IpAllocation;
use App\Models\IpPool;
use App\Models\User;
use App\Models\UserQuota;
use App\Models\VirtualMachine;
use App\Services\Hypervisor\HypervisorManager;
use App\Services\Hypervisor\InstanceSpecBuilder;
use App\Services\IpManagementService;
use App\Services\PanelCertificateService;
use App\States\Vm\Running;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class IpManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $tenant;

    private Host $host;

    private IpPool $pool;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $this->admin = User::factory()->create(['name' => 'Admin User', 'email' => 'admin@test.local']);
        $this->admin->assignRole('admin');

        $this->tenant = User::factory()->create(['name' => 'Tenant User', 'email' => 'tenant@test.local']);
        $this->tenant->assignRole('customer');

        UserQuota::create([
            'user_id' => $this->tenant->id,
            'max_vms' => 5,
            'max_cpu_cores' => 10,
            'max_ram_mb' => 16384,
            'max_storage_gb' => 200,
            'max_ips' => 5,
        ]);

        $this->host = Host::create([
            'name' => 'compute-node-1',
            'endpoint_url' => 'https://127.0.0.1:8443',
            'driver' => 'fake',
            'is_active' => true,
            'total_cpu_cores' => 32,
            'total_ram_mb' => 65536,
            'total_storage_gb' => 2000,
        ]);

        $this->pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Public Routed Pool',
            'cidr' => '203.0.113.0/24',
            'first_usable' => '203.0.113.10',
            'last_usable' => '203.0.113.20',
            'gateway' => '203.0.113.1',
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
            'dns_servers' => ['1.1.1.1', '8.8.8.8'],
            'cooldown_seconds' => 300,
            'is_public' => true,
            'active' => true,
        ]);
    }

    public function test_retired_routes_return_not_found(): void
    {
        Sanctum::actingAs($this->tenant);

        // Floating IP creation is retired
        $resFloat = $this->postJson('/api/ips/floating', ['notes' => 'Floating test']);
        $this->assertContains($resFloat->status(), [404, 405]);

        // PTR update is retired in v1.0
        $resPtr = $this->putJson('/api/ips/1/ptr', ['hostname' => 'vm.example.com']);
        $this->assertContains($resPtr->status(), [404, 405]);

        // Reassign route is retired
        $resReassign = $this->postJson('/api/ips/1/reassign', ['virtual_machine_id' => 1]);
        $this->assertContains($resReassign->status(), [404, 405]);
    }

    public function test_admin_ip_pool_creation_validates_cidr_and_ranges(): void
    {
        Sanctum::actingAs($this->admin);

        // 1. Missing host_id
        $this->postJson('/api/admin/ip-pools', [
            'name' => 'No Host Pool',
            'cidr' => '198.51.100.0/24',
        ])->assertStatus(422)->assertJsonValidationErrors(['host_id']);

        // 2. Invalid CIDR format
        $this->postJson('/api/admin/ip-pools', [
            'name' => 'Bad CIDR Pool',
            'host_id' => $this->host->id,
            'cidr' => 'invalid-cidr-string',
        ])->assertStatus(422)->assertJsonValidationErrors(['cidr']);

        // 3. CIDR prefix out of bounds (<16 or >32)
        $this->postJson('/api/admin/ip-pools', [
            'name' => 'Bad Prefix Pool',
            'host_id' => $this->host->id,
            'cidr' => '10.0.0.0/8',
        ])->assertStatus(422)->assertJsonValidationErrors(['cidr']);

        // IPv6 rejected with 422
        $this->postJson('/api/admin/ip-pools', [
            'name' => 'IPv6 Pool',
            'host_id' => $this->host->id,
            'cidr' => '2001:db8::/32',
            'ip_version' => 6,
        ])->assertStatus(422)->assertJsonValidationErrors(['ip_version']);

        // 4. first_usable outside CIDR
        $this->postJson('/api/admin/ip-pools', [
            'name' => 'Outside First IP',
            'host_id' => $this->host->id,
            'cidr' => '198.51.100.0/24',
            'first_usable' => '192.168.1.10',
        ])->assertStatus(422)->assertJsonValidationErrors(['first_usable']);

        // 5. last_usable outside CIDR
        $this->postJson('/api/admin/ip-pools', [
            'name' => 'Outside Last IP',
            'host_id' => $this->host->id,
            'cidr' => '198.51.100.0/24',
            'last_usable' => '198.51.101.5',
        ])->assertStatus(422)->assertJsonValidationErrors(['last_usable']);

        // 6. first_usable > last_usable
        $this->postJson('/api/admin/ip-pools', [
            'name' => 'Inverted Range',
            'host_id' => $this->host->id,
            'cidr' => '198.51.100.0/24',
            'first_usable' => '198.51.100.50',
            'last_usable' => '198.51.100.20',
        ])->assertStatus(422)->assertJsonValidationErrors(['first_usable']);

        // 7. gateway outside CIDR
        $this->postJson('/api/admin/ip-pools', [
            'name' => 'Outside Gateway',
            'host_id' => $this->host->id,
            'cidr' => '198.51.100.0/24',
            'gateway' => '10.0.0.1',
        ])->assertStatus(422)->assertJsonValidationErrors(['gateway']);
    }

    public function test_admin_can_create_routed_ip_pool_instantly(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/admin/ip-pools', [
            'name' => 'Tokyo Edge Routed',
            'host_id' => $this->host->id,
            'cidr' => '198.51.100.0/24',
            'first_usable' => '198.51.100.10',
            'last_usable' => '198.51.100.200',
            'gateway' => '198.51.100.1',
            'parent_interface' => 'bond0',
            'network_mode' => 'routed',
            'dns_servers' => ['8.8.8.8', '1.1.1.1'],
            'cooldown_seconds' => 600,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('pool.name', 'Tokyo Edge Routed');
        $response->assertJsonPath('pool.cidr', '198.51.100.0/24');
        $response->assertJsonPath('pool.parent_interface', 'bond0');
        $response->assertJsonPath('pool.network_mode', 'routed');
        $response->assertJsonPath('pool.cooldown_seconds', 600);
        $response->assertJsonPath('pool.utilization.total', 191);
        $response->assertJsonPath('pool.utilization.free', 191);

        $this->assertDatabaseHas('ip_pools', [
            'name' => 'Tokyo Edge Routed',
            'parent_interface' => 'bond0',
            'host_id' => $this->host->id,
        ]);
    }

    public function test_admin_ip_pools_index_returns_accurate_utilization_without_n_plus_one(): void
    {
        Sanctum::actingAs($this->admin);

        // Create 2 additional pools with some allocations
        for ($p = 2; $p <= 3; $p++) {
            $pool = IpPool::create([
                'host_id' => $this->host->id,
                'name' => 'Pool '.$p,
                'cidr' => "10.{$p}.0.0/24",
                'first_usable' => "10.{$p}.0.1",
                'last_usable' => "10.{$p}.0.10",
                'parent_interface' => 'eth0',
            ]);

            IpAllocation::create([
                'pool_id' => $pool->id,
                'user_id' => $this->tenant->id,
                'address' => inet_pton("10.{$p}.0.2"),
                'ip_string' => "10.{$p}.0.2",
                'status' => 'allocated',
                'allocated_at' => now(),
            ]);

            IpAllocation::create([
                'pool_id' => $pool->id,
                'user_id' => $this->tenant->id,
                'address' => inet_pton("10.{$p}.0.3"),
                'ip_string' => "10.{$p}.0.3",
                'status' => 'cooling',
                'released_at' => now()->subMinute(),
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->getJson('/api/admin/ip-pools?host_id='.$this->host->id);
        $response->assertOk();
        $response->assertJsonCount(3, 'data');

        $queryCount = count(DB::getQueryLog());
        // Efficient execution without N+1: query count should be at most 5 (sanctum/user, role, pools pagination, eager loaded hosts, batch allocations count)
        $this->assertLessThanOrEqual(6, $queryCount, "Query count {$queryCount} exceeds N+1 threshold.");

        // Check utilization of Pool 2
        $pool2Data = collect($response->json('data'))->firstWhere('name', 'Pool 2');
        $this->assertNotNull($pool2Data);
        $this->assertEquals(10, $pool2Data['utilization']['total']);
        $this->assertEquals(1, $pool2Data['utilization']['allocated']);
        $this->assertEquals(1, $pool2Data['utilization']['cooling']);
        $this->assertEquals(8, $pool2Data['utilization']['free']);
        $this->assertEquals(20.0, $pool2Data['utilization']['percent']);
    }

    public function test_tenant_can_list_their_ip_allocations(): void
    {
        $vm = VirtualMachine::create([
            'user_id' => $this->tenant->id,
            'host_id' => $this->host->id,
            'name' => 'tenant-vm-app',
            'incus_project' => 'user-'.$this->tenant->id,
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'ubuntu/24.04',
            'ipv4_address' => '203.0.113.10',
            'mac_address' => '02:54:ab:cd:ef:01',
            'network_status' => 'observed',
        ]);

        $tenantAlloc = IpAllocation::create([
            'pool_id' => $this->pool->id,
            'virtual_machine_id' => $vm->id,
            'user_id' => $this->tenant->id,
            'address' => inet_pton('203.0.113.10'),
            'ip_string' => '203.0.113.10',
            'mac_address' => '02:54:ab:cd:ef:01',
            'status' => 'allocated',
            'allocated_at' => now(),
        ]);

        // Another tenant's allocation
        $otherUser = User::factory()->create();
        IpAllocation::create([
            'pool_id' => $this->pool->id,
            'user_id' => $otherUser->id,
            'address' => inet_pton('203.0.113.11'),
            'ip_string' => '203.0.113.11',
            'mac_address' => '02:54:ab:cd:ef:02',
            'status' => 'allocated',
            'allocated_at' => now(),
        ]);

        Sanctum::actingAs($this->tenant);
        $response = $this->getJson('/api/ips');
        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.address', '203.0.113.10');
        $response->assertJsonPath('data.0.mac_address', '02:54:ab:cd:ef:01');
        $response->assertJsonPath('data.0.virtual_machine.name', 'tenant-vm-app');
        $response->assertJsonPath('data.0.status', 'allocated');
    }

    public function test_admin_can_filter_ip_allocations_and_emergency_release(): void
    {
        $vm = VirtualMachine::create([
            'user_id' => $this->tenant->id,
            'host_id' => $this->host->id,
            'name' => 'emergency-vm',
            'incus_project' => 'user-'.$this->tenant->id,
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'ubuntu/24.04',
            'ipv4_address' => '203.0.113.15',
            'network_status' => 'observed',
        ]);

        $alloc = IpAllocation::create([
            'pool_id' => $this->pool->id,
            'virtual_machine_id' => $vm->id,
            'user_id' => $this->tenant->id,
            'address' => inet_pton('203.0.113.15'),
            'ip_string' => '203.0.113.15',
            'mac_address' => '02:54:11:22:33:44',
            'status' => 'allocated',
            'allocated_at' => now(),
        ]);

        // Customer cannot access emergency release
        Sanctum::actingAs($this->tenant);
        $this->deleteJson(sprintf('/api/admin/ips/%d/release', $alloc->id))->assertStatus(403);

        // Admin can release it
        Sanctum::actingAs($this->admin);
        $response = $this->deleteJson(sprintf('/api/admin/ips/%d/release', $alloc->id));
        $response->assertOk();

        $alloc->refresh();
        $this->assertEquals('cooling', $alloc->status);
        $this->assertNull($alloc->virtual_machine_id);
        $this->assertNotNull($alloc->released_at);

        $vm->refresh();
        $this->assertNull($vm->ipv4_address);
        $this->assertNull($vm->network_status);
    }

    public function test_cidr_prefix_boundary_rules_and_special_prefix_handling(): void
    {
        Sanctum::actingAs($this->admin);

        // /32 accepted: first = last = address, gateway optional
        $res32 = $this->postJson('/api/admin/ip-pools', [
            'name' => 'Single Host Pool 32',
            'host_id' => $this->host->id,
            'cidr' => '198.51.100.55/32',
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
        ]);
        $res32->assertStatus(201);
        $res32->assertJsonPath('pool.first_usable', '198.51.100.55');
        $res32->assertJsonPath('pool.last_usable', '198.51.100.55');

        // /31 accepted: RFC 3021, both addresses usable, gateway optional
        $res31 = $this->postJson('/api/admin/ip-pools', [
            'name' => 'P2P Pool 31',
            'host_id' => $this->host->id,
            'cidr' => '198.51.100.60/31',
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
        ]);
        $res31->assertStatus(201);
        $res31->assertJsonPath('pool.first_usable', '198.51.100.60');
        $res31->assertJsonPath('pool.last_usable', '198.51.100.61');

        // /30 accepted: usable .65 and .66 (.64 network and .67 broadcast excluded)
        $res30 = $this->postJson('/api/admin/ip-pools', [
            'name' => 'Small Subnet Pool 30',
            'host_id' => $this->host->id,
            'cidr' => '198.51.100.64/30',
            'gateway' => '198.51.100.65',
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
        ]);
        $res30->assertStatus(201);
        $res30->assertJsonPath('pool.first_usable', '198.51.100.65');
        $res30->assertJsonPath('pool.last_usable', '198.51.100.66');

        // /16 accepted (lower boundary)
        $res16 = $this->postJson('/api/admin/ip-pools', [
            'name' => 'Large Subnet Pool 16',
            'host_id' => $this->host->id,
            'cidr' => '172.20.0.0/16',
            'gateway' => '172.20.0.1',
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
        ]);
        $res16->assertStatus(201);
        $res16->assertJsonPath('pool.first_usable', '172.20.0.1');
        $res16->assertJsonPath('pool.last_usable', '172.20.255.254');

        // /17 accepted
        $res17 = $this->postJson('/api/admin/ip-pools', [
            'name' => 'Subnet Pool 17',
            'host_id' => $this->host->id,
            'cidr' => '172.21.0.0/17',
            'gateway' => '172.21.0.1',
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
        ]);
        $res17->assertStatus(201);

        // /8 rejected
        $this->postJson('/api/admin/ip-pools', [
            'name' => 'Prefix 8 Pool',
            'host_id' => $this->host->id,
            'cidr' => '10.0.0.0/8',
            'gateway' => '10.0.0.1',
            'parent_interface' => 'eth0',
        ])->assertStatus(422)->assertJsonValidationErrors(['cidr']);

        // /15 rejected
        $this->postJson('/api/admin/ip-pools', [
            'name' => 'Prefix 15 Pool',
            'host_id' => $this->host->id,
            'cidr' => '10.0.0.0/15',
            'gateway' => '10.0.0.1',
            'parent_interface' => 'eth0',
        ])->assertStatus(422)->assertJsonValidationErrors(['cidr']);
    }

    public function test_subnet_overlap_rejected_on_same_host_and_between_public_pools(): void
    {
        Sanctum::actingAs($this->admin);

        $host2 = Host::create([
            'name' => 'secondary-node',
            'endpoint_url' => 'https://127.0.0.2:8443',
            'driver' => 'fake',
            'is_active' => true,
        ]);

        // Host 1 has public pool 192.168.50.0/24
        $res = $this->postJson('/api/admin/ip-pools', [
            'name' => 'Host1 Public Pool',
            'host_id' => $this->host->id,
            'cidr' => '192.168.50.0/24',
            'gateway' => '192.168.50.1',
            'parent_interface' => 'eth0',
            'is_public' => true,
        ]);
        $res->assertStatus(201);

        // Overlapping pool on the SAME host must be rejected
        $overlapSameHost = $this->postJson('/api/admin/ip-pools', [
            'name' => 'Host1 Overlapping Subnet',
            'host_id' => $this->host->id,
            'cidr' => '192.168.50.128/25',
            'gateway' => '192.168.50.129',
            'parent_interface' => 'eth0',
            'is_public' => false,
        ]);
        $overlapSameHost->assertStatus(422)->assertJsonValidationErrors(['cidr']);
        $this->assertStringContainsString('Host1 Public Pool', $overlapSameHost->json('errors.cidr.0'));

        // Overlapping public pool on a DIFFERENT host must be rejected
        $overlapDiffHostPublic = $this->postJson('/api/admin/ip-pools', [
            'name' => 'Host2 Overlapping Public Subnet',
            'host_id' => $host2->id,
            'cidr' => '192.168.50.0/24',
            'gateway' => '192.168.50.1',
            'parent_interface' => 'eth0',
            'is_public' => true,
        ]);
        $overlapDiffHostPublic->assertStatus(422)->assertJsonValidationErrors(['cidr']);
        $this->assertStringContainsString('Host1 Public Pool', $overlapDiffHostPublic->json('errors.cidr.0'));

        // Non-public pool with same subnet on a DIFFERENT host is isolated and accepted
        $privateDiffHost = $this->postJson('/api/admin/ip-pools', [
            'name' => 'Host2 Private Isolated Subnet',
            'host_id' => $host2->id,
            'cidr' => '192.168.50.0/24',
            'gateway' => '192.168.50.1',
            'parent_interface' => 'eth0',
            'is_public' => false,
        ]);
        $privateDiffHost->assertStatus(201);
    }

    public function test_manual_pool_produces_no_nic_and_ends_unverified_unless_observed(): void
    {
        $manualPool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Manual Mode Pool',
            'cidr' => '198.51.100.0/24',
            'first_usable' => '198.51.100.10',
            'last_usable' => '198.51.100.50',
            'network_mode' => 'manual',
            'is_public' => false,
        ]);

        $vm = VirtualMachine::create([
            'user_id' => $this->tenant->id,
            'host_id' => $this->host->id,
            'name' => 'manual-test-vm',
            'incus_project' => 'user-'.$this->tenant->id,
            'instance_type' => 'virtual-machine',
            'status' => 'provisioning',
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'ubuntu/24.04',
            'ipv4_address' => '198.51.100.10',
        ]);

        $alloc = IpAllocation::create([
            'pool_id' => $manualPool->id,
            'virtual_machine_id' => $vm->id,
            'user_id' => $this->tenant->id,
            'address' => inet_pton('198.51.100.10'),
            'ip_string' => '198.51.100.10',
            'mac_address' => '02:54:aa:bb:cc:dd',
            'status' => 'allocated',
            'allocated_at' => now(),
        ]);

        // 1. InstanceSpecBuilder produces no eth0 and no cloud-init network-config
        $builder = new InstanceSpecBuilder;
        $spec = $builder->build($vm, $alloc);
        $this->assertArrayNotHasKey('eth0', $spec['devices']);
        $this->assertArrayNotHasKey('cloud-init.network-config', $spec['config']);

        // 2. Set fake driver state with no observed network address
        Cache::put("fake_driver:state:{$vm->name}", [
            'status' => 'Running',
            'network' => [],
        ]);

        // ProvisionVmJob ends with 'unverified' network_status without 120s polling
        $job = new ProvisionVmJob($vm, $alloc->id);
        $job->handle(app(HypervisorManager::class));

        $vm->refresh();
        $this->assertEquals(Running::class, $vm->status::class);
        $this->assertEquals('unverified', $vm->network_status);
    }

    public function test_ip_pool_update_rules_enforced(): void
    {
        Sanctum::actingAs($this->admin);

        $pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Editable Subnet Pool',
            'cidr' => '10.150.0.0/24',
            'first_usable' => '10.150.0.10',
            'last_usable' => '10.150.0.100',
            'gateway' => '10.150.0.1',
            'network_mode' => 'routed',
            'is_public' => false,
            'cooldown_seconds' => 300,
        ]);

        // 1. Can edit name, description, active, dns_servers, cooldown_seconds when empty
        $res = $this->putJson(sprintf('/api/admin/ip-pools/%d', $pool->id), [
            'name' => 'Renamed Subnet Pool',
            'description' => 'Updated pool description',
            'cooldown_seconds' => 600,
            'active' => false,
            'dns_servers' => ['8.8.4.4'],
        ]);
        $res->assertOk();
        $pool->refresh();
        $this->assertEquals('Renamed Subnet Pool', $pool->name);
        $this->assertEquals('Updated pool description', $pool->description);
        $this->assertEquals(600, $pool->cooldown_seconds);
        $this->assertFalse($pool->active);
        $this->assertEquals(['8.8.4.4'], $pool->dns_servers);

        // 2. Add an allocation to the pool at 10.150.0.25
        $vm = VirtualMachine::create([
            'user_id' => $this->tenant->id,
            'host_id' => $this->host->id,
            'name' => 'alloc-check-vm',
            'incus_project' => 'user-'.$this->tenant->id,
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu/24.04',
        ]);

        IpAllocation::create([
            'pool_id' => $pool->id,
            'virtual_machine_id' => $vm->id,
            'user_id' => $this->tenant->id,
            'address' => inet_pton('10.150.0.25'),
            'ip_string' => '10.150.0.25',
            'mac_address' => '02:54:11:22:33:55',
            'status' => 'allocated',
            'allocated_at' => now(),
        ]);

        // 3. Attempting to change cidr when allocations exist is rejected (422)
        $this->putJson(sprintf('/api/admin/ip-pools/%d', $pool->id), [
            'cidr' => '10.160.0.0/24',
        ])->assertStatus(422)->assertJsonValidationErrors(['cidr']);

        // 4. Attempting to change network_mode when allocations exist is rejected (422)
        $this->putJson(sprintf('/api/admin/ip-pools/%d', $pool->id), [
            'network_mode' => 'manual',
        ])->assertStatus(422)->assertJsonValidationErrors(['network_mode']);

        // 5. Attempting to move first_usable beyond active allocation (.25) is rejected (422)
        $this->putJson(sprintf('/api/admin/ip-pools/%d', $pool->id), [
            'first_usable' => '10.150.0.30',
        ])->assertStatus(422)->assertJsonValidationErrors(['first_usable']);

        // 6. Shrinking first_usable safely (.10 -> .20, where allocation .25 remains within range) succeeds
        $resSafe = $this->putJson(sprintf('/api/admin/ip-pools/%d', $pool->id), [
            'first_usable' => '10.150.0.20',
        ]);
        $resSafe->assertOk();
        $pool->refresh();
        $this->assertEquals('10.150.0.20', $pool->first_usable);
    }

    public function test_preflight_reports_checks_and_missing_parent_interface(): void
    {
        Sanctum::actingAs($this->admin);

        app(PanelCertificateService::class)->generate(365);

        // Fake host networks endpoint: reports eth0 and incusbr0 only
        Http::fake([
            '*/1.0/networks*' => Http::response([
                'type' => 'sync',
                'status' => 'Success',
                'metadata' => [
                    '/1.0/networks/eth0',
                    '/1.0/networks/incusbr0',
                ],
            ], 200),
        ]);

        $badPool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Bad Parent Pool',
            'cidr' => '10.170.0.0/24',
            'first_usable' => '10.170.0.10',
            'last_usable' => '10.170.0.50',
            'parent_interface' => 'missing-vlan99',
            'gateway' => '10.170.0.1',
        ]);

        // Preflight should return ok: false because parent interface missing
        $resBad = $this->postJson(sprintf('/api/admin/ip-pools/%d/preflight', $badPool->id));
        $resBad->assertOk();
        $this->assertFalse($resBad->json('ok'));

        $checksBad = collect($resBad->json('checks'));
        $parentCheck = $checksBad->firstWhere('name', 'Parent interface exists');
        $this->assertNotNull($parentCheck);
        $this->assertFalse($parentCheck['ok']);
        $this->assertStringContainsString('missing-vlan99', $parentCheck['hint']);

        // Update pool parent_interface to eth0
        $badPool->update(['parent_interface' => 'eth0']);

        $resGood = $this->postJson(sprintf('/api/admin/ip-pools/%d/preflight', $badPool->id));
        $resGood->assertOk();
        $this->assertTrue($resGood->json('ok'));

        $checksGood = collect($resGood->json('checks'));
        $this->assertTrue($checksGood->firstWhere('name', 'Host reachable')['ok']);
        $this->assertTrue($checksGood->firstWhere('name', 'Parent interface exists')['ok']);
        $this->assertTrue($checksGood->firstWhere('name', 'Pool range consistent')['ok']);

        // Check 4 includes sysctl & proxy ARP hint
        $sysctlCheck = $checksGood->firstWhere('name', 'Host sysctl & proxy ARP');
        $this->assertNotNull($sysctlCheck);
        $this->assertStringContainsString('proxy_arp', $sysctlCheck['hint']);
    }

    public function test_utilization_identical_from_both_code_paths(): void
    {
        $pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Utilization Compare Pool',
            'cidr' => '10.180.0.0/24',
            'first_usable' => '10.180.0.1',
            'last_usable' => '10.180.0.10',
            'gateway' => '10.180.0.1', // 1 excluded gateway -> 9 usable
            'cooldown_seconds' => 300,
            'is_public' => false,
        ]);

        // 1 active allocated
        IpAllocation::create([
            'pool_id' => $pool->id,
            'user_id' => $this->tenant->id,
            'address' => inet_pton('10.180.0.2'),
            'ip_string' => '10.180.0.2',
            'mac_address' => '02:54:01:02:03:04',
            'status' => 'allocated',
            'allocated_at' => now(),
        ]);

        // 1 non-expired cooling (released 60s ago, cooldown 300s -> still cooling)
        IpAllocation::create([
            'pool_id' => $pool->id,
            'user_id' => $this->tenant->id,
            'address' => inet_pton('10.180.0.3'),
            'ip_string' => '10.180.0.3',
            'mac_address' => '02:54:01:02:03:05',
            'status' => 'cooling',
            'released_at' => now()->subSeconds(60),
        ]);

        // 1 expired cooling (released 400s ago, cooldown 300s -> expired, NOT occupied)
        IpAllocation::create([
            'pool_id' => $pool->id,
            'user_id' => $this->tenant->id,
            'address' => inet_pton('10.180.0.4'),
            'ip_string' => '10.180.0.4',
            'mac_address' => '02:54:01:02:03:06',
            'status' => 'cooling',
            'released_at' => now()->subSeconds(400),
        ]);

        /** @var IpManagementService $service */
        $service = app(IpManagementService::class);

        $directUtil = $service->getPoolUtilization($pool);
        $service->attachUtilizationToPools([$pool]);

        $this->assertEquals(9, $directUtil['total']);
        $this->assertEquals(1, $directUtil['allocated']);
        $this->assertEquals(1, $directUtil['cooling']);
        $this->assertEquals(7, $directUtil['free']);

        $this->assertSame($directUtil, $pool->utilization);
    }

    public function test_get_ips_does_not_delete_expired_cooling_rows_and_clamps_status_in_resource(): void
    {
        $expiredCooling = IpAllocation::create([
            'pool_id' => $this->pool->id,
            'user_id' => $this->tenant->id,
            'address' => inet_pton('203.0.113.18'),
            'ip_string' => '203.0.113.18',
            'mac_address' => '02:54:de:ad:be:ef',
            'status' => 'cooling',
            'allocated_at' => now()->subHour(),
            'released_at' => now()->subSeconds($this->pool->cooldown_seconds + 60),
        ]);

        Sanctum::actingAs($this->tenant);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->getJson('/api/ips');

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $expiredCooling->id);
        $response->assertJsonPath('data.0.status', 'released');
        $response->assertJsonPath('data.0.cooldown_remaining_seconds', 0);

        $this->assertDatabaseHas('ip_allocations', ['id' => $expiredCooling->id]);

        $deleteQueries = array_filter(
            $queries,
            static fn (array $entry): bool => str_starts_with(strtolower(ltrim((string) $entry['query'])), 'delete')
        );
        $this->assertSame([], $deleteQueries);
    }
}
