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
}
