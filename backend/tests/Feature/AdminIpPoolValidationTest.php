<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Host;
use App\Models\IpAllocation;
use App\Models\IpPool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AdminIpPoolValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Host $host;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->admin = User::factory()->create(['email' => 'pool-admin@test.local']);
        $this->admin->assignRole('admin');

        $this->host = Host::create([
            'name' => 'pool-val-host',
            'endpoint_url' => 'https://127.0.0.1:8443',
            'driver' => 'fake',
            'is_active' => true,
        ]);
    }

    public function test_creating_routed_or_default_pool_without_parent_interface_returns_422(): void
    {
        Sanctum::actingAs($this->admin);

        // Explicit routed mode without parent_interface
        $this->postJson('/api/admin/ip-pools', [
            'name' => 'Routed Without Interface',
            'host_id' => $this->host->id,
            'cidr' => '198.51.100.0/24',
            'gateway' => '198.51.100.1',
            'network_mode' => 'routed',
        ])->assertStatus(422)->assertJsonValidationErrors(['parent_interface']);

        // Missing network_mode (defaults to routed) without parent_interface
        $this->postJson('/api/admin/ip-pools', [
            'name' => 'Default Mode Without Interface',
            'host_id' => $this->host->id,
            'cidr' => '198.51.101.0/24',
            'gateway' => '198.51.101.1',
        ])->assertStatus(422)->assertJsonValidationErrors(['parent_interface']);

        // Explicit routed mode with empty string parent_interface
        $this->postJson('/api/admin/ip-pools', [
            'name' => 'Routed Empty Interface',
            'host_id' => $this->host->id,
            'cidr' => '198.51.102.0/24',
            'gateway' => '198.51.102.1',
            'network_mode' => 'routed',
            'parent_interface' => '',
        ])->assertStatus(422)->assertJsonValidationErrors(['parent_interface']);
    }

    public function test_creating_manual_pool_without_parent_interface_succeeds(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/admin/ip-pools', [
            'name' => 'Manual Without Interface',
            'host_id' => $this->host->id,
            'cidr' => '198.51.103.0/24',
            'gateway' => '198.51.103.1',
            'network_mode' => 'manual',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('pool.network_mode', 'manual');
    }

    public function test_updating_routed_pool_with_empty_parent_interface_returns_422(): void
    {
        Sanctum::actingAs($this->admin);

        $pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Routed Pool',
            'cidr' => '10.190.0.0/24',
            'first_usable' => '10.190.0.10',
            'last_usable' => '10.190.0.100',
            'gateway' => '10.190.0.1',
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
        ]);

        $this->putJson(sprintf('/api/admin/ip-pools/%d', $pool->id), [
            'parent_interface' => '',
        ])->assertStatus(422)->assertJsonValidationErrors(['parent_interface']);

        $pool->refresh();
        $this->assertSame('eth0', $pool->parent_interface);

        $this->putJson(sprintf('/api/admin/ip-pools/%d', $pool->id), [
            'parent_interface' => null,
        ])->assertStatus(422)->assertJsonValidationErrors(['parent_interface']);

        $pool->refresh();
        $this->assertSame('eth0', $pool->parent_interface);

        // Updating another field without sending parent_interface still succeeds and keeps stored value
        $this->putJson(sprintf('/api/admin/ip-pools/%d', $pool->id), [
            'name' => 'Routed Pool Renamed',
        ])->assertOk()->assertJsonPath('pool.parent_interface', 'eth0');

        $pool->refresh();
        $this->assertSame('eth0', $pool->parent_interface);
    }

    public function test_updating_manual_pool_to_empty_parent_interface_succeeds(): void
    {
        Sanctum::actingAs($this->admin);

        $pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Manual Pool',
            'cidr' => '10.191.0.0/24',
            'first_usable' => '10.191.0.10',
            'last_usable' => '10.191.0.100',
            'gateway' => '10.191.0.1',
            'network_mode' => 'manual',
            'parent_interface' => 'eth0',
        ]);

        $this->putJson(sprintf('/api/admin/ip-pools/%d', $pool->id), [
            'parent_interface' => '',
        ])->assertOk()->assertJsonPath('pool.parent_interface', null);

        $pool->refresh();
        $this->assertSame('', $pool->parent_interface);

        // Switching manual pool with empty interface to routed without providing parent_interface fails
        $manualEmptyPool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Manual Empty Pool',
            'cidr' => '10.192.0.0/24',
            'first_usable' => '10.192.0.10',
            'last_usable' => '10.192.0.100',
            'gateway' => '10.192.0.1',
            'network_mode' => 'manual',
            'parent_interface' => '',
        ]);

        $this->putJson(sprintf('/api/admin/ip-pools/%d', $manualEmptyPool->id), [
            'network_mode' => 'routed',
        ])->assertStatus(422)->assertJsonValidationErrors(['parent_interface']);
    }

    public function test_updating_routed_pool_to_manual_with_empty_parent_interface_clears_stored_value(): void
    {
        Sanctum::actingAs($this->admin);

        $pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Routed To Manual Pool',
            'cidr' => '10.193.0.0/24',
            'first_usable' => '10.193.0.10',
            'last_usable' => '10.193.0.100',
            'gateway' => '10.193.0.1',
            'network_mode' => 'routed',
            'parent_interface' => 'eno1',
        ]);

        $response = $this->putJson(sprintf('/api/admin/ip-pools/%d', $pool->id), [
            'network_mode' => 'manual',
            'parent_interface' => '',
        ]);

        $response->assertOk();
        $response->assertJsonPath('pool.network_mode', 'manual');
        $response->assertJsonPath('pool.parent_interface', null);

        $pool->refresh();
        $this->assertSame('manual', $pool->network_mode);
        $this->assertSame('', $pool->parent_interface);
    }

    public function test_updating_manual_pool_with_empty_parent_interface_stays_empty(): void
    {
        Sanctum::actingAs($this->admin);

        $pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Manual Stays Empty Pool',
            'cidr' => '10.194.0.0/24',
            'first_usable' => '10.194.0.10',
            'last_usable' => '10.194.0.100',
            'gateway' => '10.194.0.1',
            'network_mode' => 'manual',
            'parent_interface' => '',
        ]);

        $response = $this->putJson(sprintf('/api/admin/ip-pools/%d', $pool->id), [
            'parent_interface' => '',
        ]);

        $response->assertOk();
        $response->assertJsonPath('pool.parent_interface', null);

        $pool->refresh();
        $this->assertSame('', $pool->parent_interface);
    }

    public function test_updating_pool_without_parent_interface_key_leaves_stored_value_unchanged(): void
    {
        Sanctum::actingAs($this->admin);

        $pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Keep Interface Pool',
            'cidr' => '10.195.0.0/24',
            'first_usable' => '10.195.0.10',
            'last_usable' => '10.195.0.100',
            'gateway' => '10.195.0.1',
            'network_mode' => 'manual',
            'parent_interface' => 'eno1',
        ]);

        $response = $this->putJson(sprintf('/api/admin/ip-pools/%d', $pool->id), [
            'name' => 'Keep Interface Pool Renamed',
        ]);

        $response->assertOk();
        $response->assertJsonPath('pool.parent_interface', 'eno1');

        $pool->refresh();
        $this->assertSame('eno1', $pool->parent_interface);
    }

    public function test_updating_pool_with_allocations_accepts_new_parent_interface(): void
    {
        Sanctum::actingAs($this->admin);

        $pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Allocated Routed Pool',
            'cidr' => '10.196.0.0/24',
            'first_usable' => '10.196.0.10',
            'last_usable' => '10.196.0.100',
            'gateway' => '10.196.0.1',
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
        ]);

        IpAllocation::create([
            'pool_id' => $pool->id,
            'user_id' => $this->admin->id,
            'address' => inet_pton('10.196.0.10'),
            'ip_string' => '10.196.0.10',
            'status' => 'allocated',
            'allocated_at' => now(),
        ]);

        $response = $this->putJson(sprintf('/api/admin/ip-pools/%d', $pool->id), [
            'parent_interface' => 'eno2',
        ]);

        $response->assertOk();
        $response->assertJsonPath('pool.parent_interface', 'eno2');

        $pool->refresh();
        $this->assertSame('eno2', $pool->parent_interface);
    }

    public function test_updating_pool_with_allocations_still_rejects_changed_cidr_host_or_network_mode(): void
    {
        Sanctum::actingAs($this->admin);

        $otherHost = Host::create([
            'name' => 'pool-val-other-host',
            'endpoint_url' => 'https://127.0.0.1:8444',
            'driver' => 'fake',
            'is_active' => true,
        ]);

        $pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Immutable Fields Pool',
            'cidr' => '10.197.0.0/24',
            'first_usable' => '10.197.0.10',
            'last_usable' => '10.197.0.100',
            'gateway' => '10.197.0.1',
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
        ]);

        IpAllocation::create([
            'pool_id' => $pool->id,
            'user_id' => $this->admin->id,
            'address' => inet_pton('10.197.0.10'),
            'ip_string' => '10.197.0.10',
            'status' => 'allocated',
            'allocated_at' => now(),
        ]);

        $this->putJson(sprintf('/api/admin/ip-pools/%d', $pool->id), [
            'cidr' => '10.198.0.0/24',
        ])->assertStatus(422)->assertJsonValidationErrors(['cidr']);

        $this->putJson(sprintf('/api/admin/ip-pools/%d', $pool->id), [
            'host_id' => $otherHost->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['host_id']);

        $this->putJson(sprintf('/api/admin/ip-pools/%d', $pool->id), [
            'network_mode' => 'bridged',
        ])->assertStatus(422)->assertJsonValidationErrors(['network_mode']);

        $pool->refresh();
        $this->assertSame('10.197.0.0/24', $pool->cidr);
        $this->assertSame($this->host->id, $pool->host_id);
        $this->assertSame('routed', $pool->network_mode);
    }

    public function test_updating_routed_pool_with_allocations_to_empty_parent_interface_returns_422(): void
    {
        Sanctum::actingAs($this->admin);

        $pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Allocated Routed Validation Pool',
            'cidr' => '10.199.0.0/24',
            'first_usable' => '10.199.0.10',
            'last_usable' => '10.199.0.100',
            'gateway' => '10.199.0.1',
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
        ]);

        IpAllocation::create([
            'pool_id' => $pool->id,
            'user_id' => $this->admin->id,
            'address' => inet_pton('10.199.0.10'),
            'ip_string' => '10.199.0.10',
            'status' => 'allocated',
            'allocated_at' => now(),
        ]);

        $this->putJson(sprintf('/api/admin/ip-pools/%d', $pool->id), [
            'parent_interface' => '',
        ])->assertStatus(422)->assertJsonValidationErrors(['parent_interface']);

        $pool->refresh();
        $this->assertSame('eth0', $pool->parent_interface);
    }

    public function test_creating_bridged_or_macvlan_pool_without_gateway_returns_422(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/admin/ip-pools', [
            'name' => 'Bridged Missing Gateway',
            'host_id' => $this->host->id,
            'cidr' => '198.51.110.0/24',
            'network_mode' => 'bridged',
            'parent_interface' => 'incusbr0',
        ])->assertStatus(422)->assertJsonValidationErrors(['gateway']);

        $this->postJson('/api/admin/ip-pools', [
            'name' => 'Macvlan Missing Gateway',
            'host_id' => $this->host->id,
            'cidr' => '198.51.111.0/24',
            'network_mode' => 'macvlan',
            'parent_interface' => 'eno1',
        ])->assertStatus(422)->assertJsonValidationErrors(['gateway']);
    }

    public function test_creating_l2_pool_with_slash_31_or_32_returns_422_on_cidr(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/admin/ip-pools', [
            'name' => 'Macvlan Slash 32',
            'host_id' => $this->host->id,
            'cidr' => '198.51.112.10/32',
            'gateway' => '198.51.112.10',
            'network_mode' => 'macvlan',
            'parent_interface' => 'eno1',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['cidr'])
            ->assertJsonPath('errors.cidr.0', 'Bridged and macvlan modes only support /16 to /30 subnets.');

        $this->postJson('/api/admin/ip-pools', [
            'name' => 'Bridged Slash 31',
            'host_id' => $this->host->id,
            'cidr' => '198.51.112.12/31',
            'gateway' => '198.51.112.12',
            'network_mode' => 'bridged',
            'parent_interface' => 'incusbr0',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['cidr'])
            ->assertJsonPath('errors.cidr.0', 'Bridged and macvlan modes only support /16 to /30 subnets.');
    }

    public function test_creating_bridged_pool_with_invalid_gateway_returns_422(): void
    {
        Sanctum::actingAs($this->admin);

        // Gateway outside CIDR
        $this->postJson('/api/admin/ip-pools', [
            'name' => 'Bridged Outside Gateway',
            'host_id' => $this->host->id,
            'cidr' => '198.51.113.0/24',
            'gateway' => '198.51.114.1',
            'network_mode' => 'bridged',
            'parent_interface' => 'incusbr0',
        ])->assertStatus(422)->assertJsonValidationErrors(['gateway']);

        // Gateway equals network address
        $this->postJson('/api/admin/ip-pools', [
            'name' => 'Bridged Network Gateway',
            'host_id' => $this->host->id,
            'cidr' => '198.51.113.0/24',
            'gateway' => '198.51.113.0',
            'network_mode' => 'bridged',
            'parent_interface' => 'incusbr0',
        ])->assertStatus(422)->assertJsonValidationErrors(['gateway']);

        // Gateway equals broadcast address
        $this->postJson('/api/admin/ip-pools', [
            'name' => 'Bridged Broadcast Gateway',
            'host_id' => $this->host->id,
            'cidr' => '198.51.113.0/24',
            'gateway' => '198.51.113.255',
            'network_mode' => 'bridged',
            'parent_interface' => 'incusbr0',
        ])->assertStatus(422)->assertJsonValidationErrors(['gateway']);

        // IPv6 gateway rejected
        $this->postJson('/api/admin/ip-pools', [
            'name' => 'Bridged IPv6 Gateway',
            'host_id' => $this->host->id,
            'cidr' => '198.51.113.0/24',
            'gateway' => '2001:db8::1',
            'network_mode' => 'bridged',
            'parent_interface' => 'incusbr0',
        ])->assertStatus(422)->assertJsonValidationErrors(['gateway']);
    }

    public function test_creating_valid_bridged_pool_returns_201_and_routed_24_behaviour_unchanged(): void
    {
        Sanctum::actingAs($this->admin);

        $bridgedResponse = $this->postJson('/api/admin/ip-pools', [
            'name' => 'Valid Bridged Pool',
            'host_id' => $this->host->id,
            'cidr' => '198.51.115.0/24',
            'gateway' => '198.51.115.1',
            'network_mode' => 'bridged',
            'parent_interface' => 'incusbr0',
        ]);

        $bridgedResponse->assertCreated();
        $bridgedResponse->assertJsonPath('pool.network_mode', 'bridged');
        $bridgedResponse->assertJsonPath('pool.gateway', '198.51.115.1');

        // Routed /24 without gateway returns 201; routed /24 with gateway 201; routed /32 without gateway 201
        $this->postJson('/api/admin/ip-pools', [
            'name' => 'Routed 24 Without Gateway',
            'host_id' => $this->host->id,
            'cidr' => '198.51.116.0/24',
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
        ])->assertCreated()
            ->assertJsonPath('pool.network_mode', 'routed')
            ->assertJsonPath('pool.gateway', null);

        $this->postJson('/api/admin/ip-pools', [
            'name' => 'Routed 24 Valid',
            'host_id' => $this->host->id,
            'cidr' => '198.51.118.0/24',
            'gateway' => '198.51.118.1',
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
        ])->assertCreated();

        $this->postJson('/api/admin/ip-pools', [
            'name' => 'Routed 32 Without Gateway',
            'host_id' => $this->host->id,
            'cidr' => '198.51.117.10/32',
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
        ])->assertCreated();
    }

    public function test_updating_routed_pool_without_allocations_to_macvlan_without_gateway_returns_422(): void
    {
        Sanctum::actingAs($this->admin);

        $routedPoolWithoutGateway = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Routed No Gateway',
            'cidr' => '10.200.0.0/24',
            'first_usable' => '10.200.0.10',
            'last_usable' => '10.200.0.100',
            'gateway' => null,
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
        ]);

        $this->putJson(sprintf('/api/admin/ip-pools/%d', $routedPoolWithoutGateway->id), [
            'network_mode' => 'macvlan',
            'parent_interface' => 'eno1',
        ])->assertStatus(422)->assertJsonValidationErrors(['gateway']);

        $routedPoolWithoutGateway->refresh();
        $this->assertSame('routed', $routedPoolWithoutGateway->network_mode);
    }

    public function test_updating_bridged_pool_clearing_gateway_returns_422(): void
    {
        Sanctum::actingAs($this->admin);

        $bridgedPool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Bridged Pool',
            'cidr' => '10.201.0.0/24',
            'first_usable' => '10.201.0.10',
            'last_usable' => '10.201.0.100',
            'gateway' => '10.201.0.1',
            'network_mode' => 'bridged',
            'parent_interface' => 'incusbr0',
        ]);

        $this->putJson(sprintf('/api/admin/ip-pools/%d', $bridgedPool->id), [
            'gateway' => null,
        ])->assertStatus(422)->assertJsonValidationErrors(['gateway']);

        $this->putJson(sprintf('/api/admin/ip-pools/%d', $bridgedPool->id), [
            'gateway' => '',
        ])->assertStatus(422)->assertJsonValidationErrors(['gateway']);

        $bridgedPool->refresh();
        $this->assertSame('10.201.0.1', $bridgedPool->gateway);
    }

    public function test_updating_bridged_or_macvlan_pool_gateway_with_allocations_returns_422(): void
    {
        Sanctum::actingAs($this->admin);

        foreach ([
            ['mode' => 'bridged', 'interface' => 'incusbr0', 'cidr' => '10.202.0.0/24', 'gw' => '10.202.0.1', 'newGw' => '10.202.0.2', 'allocIp' => '10.202.0.10'],
            ['mode' => 'macvlan', 'interface' => 'eno1', 'cidr' => '10.203.0.0/24', 'gw' => '10.203.0.1', 'newGw' => '10.203.0.2', 'allocIp' => '10.203.0.10'],
        ] as $case) {
            $subnetPrefix = substr($case['cidr'], 0, strrpos($case['cidr'], '.') + 1);
            $pool = IpPool::create([
                'host_id' => $this->host->id,
                'name' => sprintf('L2 %s Pool', $case['mode']),
                'cidr' => $case['cidr'],
                'first_usable' => $subnetPrefix.'2',
                'last_usable' => $subnetPrefix.'100',
                'gateway' => $case['gw'],
                'network_mode' => $case['mode'],
                'parent_interface' => $case['interface'],
            ]);

            IpAllocation::create([
                'pool_id' => $pool->id,
                'user_id' => $this->admin->id,
                'address' => inet_pton($case['allocIp']),
                'ip_string' => $case['allocIp'],
                'status' => 'allocated',
                'allocated_at' => now(),
            ]);

            $this->putJson(sprintf('/api/admin/ip-pools/%d', $pool->id), [
                'gateway' => $case['newGw'],
            ])->assertStatus(422)
                ->assertJsonValidationErrors(['gateway'])
                ->assertJsonPath('errors.gateway.0', 'Gateway cannot be modified when allocations exist in bridged or macvlan pools.');

            $pool->refresh();
            $this->assertSame($case['gw'], $pool->gateway);
        }
    }

    public function test_updating_gateway_or_excluded_ips_targeting_active_allocated_ip_returns_422(): void
    {
        Sanctum::actingAs($this->admin);

        $pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Routed Conflict Pool',
            'cidr' => '10.204.0.0/24',
            'first_usable' => '10.204.0.1',
            'last_usable' => '10.204.0.100',
            'gateway' => '10.204.0.1',
            'excluded_ips' => ['10.204.0.5'],
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
        ]);

        $allocation = IpAllocation::create([
            'pool_id' => $pool->id,
            'user_id' => $this->admin->id,
            'address' => inet_pton('10.204.0.10'),
            'ip_string' => '10.204.0.10',
            'status' => 'allocated',
            'allocated_at' => now(),
        ]);

        $gatewayResponse = $this->putJson(sprintf('/api/admin/ip-pools/%d', $pool->id), [
            'gateway' => '10.204.0.10',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['gateway']);

        $gatewayMessage = (string) $gatewayResponse->json('message');
        $this->assertStringContainsString('10.204.0.10', $gatewayMessage);
        $this->assertStringContainsString((string) $allocation->id, $gatewayMessage);

        $excludedResponse = $this->putJson(sprintf('/api/admin/ip-pools/%d', $pool->id), [
            'excluded_ips' => ['10.204.0.5', '10.204.0.10'],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['excluded_ips']);

        $excludedMessage = (string) $excludedResponse->json('message');
        $this->assertStringContainsString('10.204.0.10', $excludedMessage);
        $this->assertStringContainsString((string) $allocation->id, $excludedMessage);

        $pool->refresh();
        $this->assertSame('10.204.0.1', $pool->gateway);
        $this->assertSame(['10.204.0.5'], $pool->excluded_ips);
    }

    public function test_updating_pool_mode_gateway_and_cidr_succeeds_when_only_expired_cooling_allocations_exist(): void
    {
        Sanctum::actingAs($this->admin);

        $pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Expired Cooling Pool',
            'cidr' => '10.210.0.0/24',
            'first_usable' => '10.210.0.2',
            'last_usable' => '10.210.0.254',
            'gateway' => '10.210.0.1',
            'network_mode' => 'bridged',
            'parent_interface' => 'incusbr0',
            'cooldown_seconds' => 300,
        ]);

        IpAllocation::create([
            'pool_id' => $pool->id,
            'user_id' => $this->admin->id,
            'address' => inet_pton('10.210.0.10'),
            'ip_string' => '10.210.0.10',
            'status' => 'cooling',
            'allocated_at' => now()->subMinutes(15),
            'released_at' => now()->subSeconds(600),
        ]);

        // Updating gateway on a bridged pool succeeds when only expired cooling allocations exist
        $this->putJson(sprintf('/api/admin/ip-pools/%d', $pool->id), [
            'gateway' => '10.210.0.2',
        ])->assertOk()
            ->assertJsonPath('pool.gateway', '10.210.0.2');

        // Updating network_mode (bridged -> routed) succeeds
        $this->putJson(sprintf('/api/admin/ip-pools/%d', $pool->id), [
            'network_mode' => 'routed',
        ])->assertOk()
            ->assertJsonPath('pool.network_mode', 'routed');

        // Updating cidr without explicit first_usable/last_usable succeeds and recalculates usable range
        $this->putJson(sprintf('/api/admin/ip-pools/%d', $pool->id), [
            'cidr' => '10.211.0.0/24',
            'gateway' => '10.211.0.1',
        ])->assertOk()
            ->assertJsonPath('pool.cidr', '10.211.0.0/24')
            ->assertJsonPath('pool.first_usable', '10.211.0.1')
            ->assertJsonPath('pool.last_usable', '10.211.0.254');

        $pool->refresh();
        $this->assertSame('routed', $pool->network_mode);
        $this->assertSame('10.211.0.0/24', $pool->cidr);
        $this->assertSame('10.211.0.1', $pool->first_usable);
        $this->assertSame('10.211.0.254', $pool->last_usable);
    }

    public function test_link_local_addresses_in_cidr_gateway_or_excluded_ips_return_422(): void
    {
        Sanctum::actingAs($this->admin);

        // Link-local CIDR
        $this->postJson('/api/admin/ip-pools', [
            'name' => 'Link Local CIDR',
            'host_id' => $this->host->id,
            'cidr' => '169.254.10.0/24',
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
        ])->assertStatus(422)->assertJsonValidationErrors(['cidr']);

        // Link-local gateway
        $this->postJson('/api/admin/ip-pools', [
            'name' => 'Link Local Gateway',
            'host_id' => $this->host->id,
            'cidr' => '198.51.120.0/24',
            'gateway' => '169.254.0.1',
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
        ])->assertStatus(422)->assertJsonValidationErrors(['gateway']);

        // Link-local excluded IP
        $this->postJson('/api/admin/ip-pools', [
            'name' => 'Link Local Excluded',
            'host_id' => $this->host->id,
            'cidr' => '198.51.121.0/24',
            'excluded_ips' => ['169.254.1.1'],
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
        ])->assertStatus(422)->assertJsonValidationErrors(['excluded_ips']);

        // Link-local gateway and excluded_ips on update
        $pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Valid Pool For Link Local Update',
            'cidr' => '198.51.122.0/24',
            'first_usable' => '198.51.122.10',
            'last_usable' => '198.51.122.100',
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
        ]);

        $this->putJson(sprintf('/api/admin/ip-pools/%d', $pool->id), [
            'gateway' => '169.254.0.1',
        ])->assertStatus(422)->assertJsonValidationErrors(['gateway']);

        $this->putJson(sprintf('/api/admin/ip-pools/%d', $pool->id), [
            'excluded_ips' => ['169.254.10.10'],
        ])->assertStatus(422)->assertJsonValidationErrors(['excluded_ips']);
    }
}
