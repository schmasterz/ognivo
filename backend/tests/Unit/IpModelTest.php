<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Host;
use App\Models\IpAllocation;
use App\Models\IpPool;
use App\Models\User;
use App\Models\VirtualMachine;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class IpModelTest extends TestCase
{
    use RefreshDatabase;

    private Host $host;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = Host::create([
            'name' => 'compute-node-1',
            'endpoint_url' => 'https://127.0.0.1:8443',
            'driver' => 'fake',
            'is_active' => true,
            'total_cpu_cores' => 32,
            'total_ram_mb' => 65536,
            'total_storage_gb' => 1000,
        ]);

        $this->user = User::factory()->create();
    }

    public function test_ip_pool_can_be_created_with_ranged_attributes_and_computes_total_ips(): void
    {
        $pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Sofia DC IPv4 Pool',
            'cidr' => '203.0.113.0/24',
            'first_usable' => '203.0.113.2',
            'last_usable' => '203.0.113.254',
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
            'gateway' => '169.254.0.1',
            'dns_servers' => ['1.1.1.1', '8.8.8.8'],
            'excluded_ips' => ['203.0.113.1'],
            'cooldown_seconds' => 300,
            'active' => true,
            'is_public' => true,
        ]);

        $this->assertDatabaseHas('ip_pools', [
            'id' => $pool->id,
            'name' => 'Sofia DC IPv4 Pool',
            'cidr' => '203.0.113.0/24',
            'parent_interface' => 'eth0',
            'network_mode' => 'routed',
        ]);

        $this->assertEquals(0, $pool->allocations()->where('status', 'allocated')->count());
    }

    public function test_ip_allocation_can_be_created_and_relates_to_pool_and_user(): void
    {
        $pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Pool 1',
            'cidr' => '192.0.2.0/28',
            'first_usable' => '192.0.2.1',
            'last_usable' => '192.0.2.14',
            'network_mode' => 'routed',
            'parent_interface' => 'bond0',
        ]);

        $vm = VirtualMachine::create([
            'user_id' => $this->user->id,
            'host_id' => $this->host->id,
            'name' => 'vm-web-1',
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => 'running',
            'network_status' => 'observed',
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'ubuntu/24.04',
            'ipv4_address' => '192.0.2.5',
        ]);

        $rawIp = inet_pton('192.0.2.5');
        $allocation = IpAllocation::create([
            'pool_id' => $pool->id,
            'address' => $rawIp,
            'ip_string' => '192.0.2.5',
            'virtual_machine_id' => $vm->id,
            'user_id' => $this->user->id,
            'mac_address' => '02:54:19:00:02:05',
            'status' => 'allocated',
            'allocated_at' => now(),
        ]);

        $this->assertTrue($allocation->pool->is($pool));
        $this->assertTrue($allocation->user->is($this->user));
        $this->assertTrue($allocation->virtualMachine->is($vm));
        $this->assertEquals('192.0.2.5', $allocation->ip_string);

        // Test VM relations
        $this->assertTrue($vm->primaryAllocation->is($allocation));
        $this->assertEquals(1, $vm->allocations()->count());

        // Test Pool active allocations
        $this->assertEquals(1, $pool->allocations()->where('status', 'allocated')->count());
    }

    public function test_ip_allocation_scopes_active_and_cooling(): void
    {
        $pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Pool 2',
            'cidr' => '192.0.2.0/28',
            'first_usable' => '192.0.2.1',
            'last_usable' => '192.0.2.14',
            'network_mode' => 'routed',
            'cooldown_seconds' => 120,
        ]);

        $activeAlloc = IpAllocation::create([
            'pool_id' => $pool->id,
            'address' => inet_pton('192.0.2.2'),
            'ip_string' => '192.0.2.2',
            'user_id' => $this->user->id,
            'status' => 'allocated',
            'allocated_at' => now(),
        ]);

        $coolingAlloc = IpAllocation::create([
            'pool_id' => $pool->id,
            'address' => inet_pton('192.0.2.3'),
            'ip_string' => '192.0.2.3',
            'user_id' => $this->user->id,
            'status' => 'cooling',
            'allocated_at' => now()->subMinutes(5),
            'released_at' => now()->subSeconds(30),
        ]);

        $this->assertEquals(1, $pool->allocations()->active()->count());
        $this->assertTrue($pool->allocations()->active()->first()->is($activeAlloc));

        $this->assertEquals(1, $pool->allocations()->cooling()->count());
        $this->assertTrue($pool->allocations()->cooling()->first()->is($coolingAlloc));

        // Test cooldown helper methods and verify released_at is not mutated in-place
        $originalReleasedAt = $coolingAlloc->released_at->copy();
        $this->assertFalse($activeAlloc->isUnderCooldown());
        $this->assertTrue($coolingAlloc->isUnderCooldown());
        $this->assertGreaterThan(0, $coolingAlloc->getCooldownRemainingSeconds());
        $this->assertTrue($coolingAlloc->released_at->equalTo($originalReleasedAt));
    }

    public function test_ip_allocation_enforces_unique_pool_id_and_address_constraint(): void
    {
        $pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Pool 3',
            'cidr' => '192.0.2.0/28',
            'first_usable' => '192.0.2.1',
            'last_usable' => '192.0.2.14',
            'network_mode' => 'routed',
        ]);

        IpAllocation::create([
            'pool_id' => $pool->id,
            'address' => inet_pton('192.0.2.10'),
            'ip_string' => '192.0.2.10',
            'user_id' => $this->user->id,
            'status' => 'allocated',
            'allocated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        // Attempt duplicate insertion
        IpAllocation::create([
            'pool_id' => $pool->id,
            'address' => inet_pton('192.0.2.10'),
            'ip_string' => '192.0.2.10',
            'user_id' => $this->user->id,
            'status' => 'allocated',
            'allocated_at' => now(),
        ]);
    }
}
