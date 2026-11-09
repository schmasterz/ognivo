<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTO\Network\CreateIpPoolDTO;
use App\Exceptions\IpPoolExhaustedException;
use App\Models\Host;
use App\Models\IpPool;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\IpManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class IpManagementServiceTest extends TestCase
{
    use RefreshDatabase;

    private IpManagementService $service;

    private Host $host;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new IpManagementService;

        $this->host = Host::create([
            'name' => 'compute-node-bg',
            'endpoint_url' => 'https://127.0.0.1:8443',
            'driver' => 'fake',
            'is_active' => true,
            'total_cpu_cores' => 32,
            'total_ram_mb' => 65536,
            'total_storage_gb' => 1000,
        ]);

        $this->user = User::factory()->create();
    }

    private function createVm(string $name): VirtualMachine
    {
        return VirtualMachine::create([
            'user_id' => $this->user->id,
            'host_id' => $this->host->id,
            'name' => $name,
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => 'provisioning',
            'network_status' => 'unassigned',
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'ubuntu/24.04',
        ]);
    }

    public function test_allocate_ip_assigns_lowest_free_ip_and_sets_vm_status(): void
    {
        $pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Small Pool',
            'cidr' => '192.168.10.0/29',
            'first_usable' => '192.168.10.2',
            'last_usable' => '192.168.10.6',
            'gateway' => '192.168.10.1',
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
        ]);

        $vm1 = $this->createVm('vm-1');
        $vm2 = $this->createVm('vm-2');

        $alloc1 = $this->service->allocateIp($pool, $vm1, $this->user);
        $this->assertEquals('192.168.10.2', $alloc1->ip_string);
        $this->assertEquals('allocated', $alloc1->status);
        $this->assertStringStartsWith('02:54:', $alloc1->mac_address);

        $vm1->refresh();
        $this->assertEquals('192.168.10.2', $vm1->ipv4_address);
        $this->assertEquals('assigned', $vm1->network_status);

        $alloc2 = $this->service->allocateIp($pool, $vm2, $this->user);
        $this->assertEquals('192.168.10.3', $alloc2->ip_string);
    }

    public function test_allocate_ip_skips_gateway_and_excluded_ips(): void
    {
        $pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Excluded Pool',
            'cidr' => '192.168.20.0/29',
            'first_usable' => '192.168.20.1',
            'last_usable' => '192.168.20.6',
            'gateway' => '192.168.20.1',
            'excluded_ips' => ['192.168.20.2', '192.168.20.3'],
            'network_mode' => 'routed',
        ]);

        $vm = $this->createVm('vm-skip');
        $alloc = $this->service->allocateIp($pool, $vm, $this->user);

        // 192.168.20.1 is gateway, .2 and .3 are excluded, so first available must be .4
        $this->assertEquals('192.168.20.4', $alloc->ip_string);
    }

    public function test_release_ip_transitions_to_cooling_state_and_clears_vm_status(): void
    {
        $pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Release Pool',
            'cidr' => '192.168.30.0/29',
            'first_usable' => '192.168.30.2',
            'last_usable' => '192.168.30.6',
            'network_mode' => 'routed',
            'cooldown_seconds' => 300,
        ]);

        $vm = $this->createVm('vm-release');
        $alloc = $this->service->allocateIp($pool, $vm, $this->user);

        $this->service->releaseIp($vm);

        $alloc->refresh();
        $this->assertEquals('cooling', $alloc->status);
        $this->assertNull($alloc->virtual_machine_id);
        $this->assertNotNull($alloc->released_at);

        $vm->refresh();
        $this->assertNull($vm->ipv4_address);
        $this->assertEquals('unassigned', $vm->network_status);
    }

    public function test_cooling_ip_is_quarantined_then_reused_after_cooldown_expiry(): void
    {
        $pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Cooldown Pool',
            'cidr' => '192.168.40.0/30', // only 2 usable IPs: .1 and .2
            'first_usable' => '192.168.40.1',
            'last_usable' => '192.168.40.2',
            'network_mode' => 'routed',
            'cooldown_seconds' => 60,
        ]);

        $vm1 = $this->createVm('vm-c1');
        $vm2 = $this->createVm('vm-c2');
        $vm3 = $this->createVm('vm-c3');

        $alloc1 = $this->service->allocateIp($pool, $vm1, $this->user); // gets .1
        $alloc2 = $this->service->allocateIp($pool, $vm2, $this->user); // gets .2

        $this->assertEquals('192.168.40.1', $alloc1->ip_string);
        $this->assertEquals('192.168.40.2', $alloc2->ip_string);

        // Release .1 into cooling
        $this->service->releaseIp($vm1);

        // Pool is full because .1 is still cooling (< 60s) and .2 is allocated
        $this->expectException(IpPoolExhaustedException::class);
        $this->service->allocateIp($pool, $vm3, $this->user);
    }

    public function test_cooling_ip_is_reused_without_duplicate_key_error_after_cooldown(): void
    {
        $pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Cooldown Reuse Pool',
            'cidr' => '192.168.50.0/30',
            'first_usable' => '192.168.50.1',
            'last_usable' => '192.168.50.1', // single IP pool
            'network_mode' => 'routed',
            'cooldown_seconds' => 60,
        ]);

        $vm1 = $this->createVm('vm-r1');
        $alloc1 = $this->service->allocateIp($pool, $vm1, $this->user);
        $this->service->releaseIp($vm1);

        // Artificially expire the cooldown by setting released_at to 120 seconds ago
        $alloc1->refresh();
        $alloc1->released_at = now()->subSeconds(120);
        $alloc1->save();

        // Allocate for a new VM: must reuse the row without throwing SQL Duplicate Entry
        $vm2 = $this->createVm('vm-r2');
        $alloc2 = $this->service->allocateIp($pool, $vm2, $this->user);

        $this->assertEquals($alloc1->id, $alloc2->id); // Reused same row
        $this->assertEquals('allocated', $alloc2->status);
        $this->assertEquals($vm2->id, $alloc2->virtual_machine_id);
        $this->assertNull($alloc2->released_at);
        $this->assertEquals('192.168.50.1', $alloc2->ip_string);
    }

    public function test_get_pool_utilization_calculates_metrics_correctly(): void
    {
        $pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Metrics Pool',
            'cidr' => '192.168.60.0/29',
            'first_usable' => '192.168.60.1',
            'last_usable' => '192.168.60.5', // 5 usable IPs
            'gateway' => '192.168.60.1',       // 1 excluded via gateway -> 4 total usable
            'network_mode' => 'routed',
            'cooldown_seconds' => 120,
        ]);

        $vm1 = $this->createVm('vm-m1');
        $vm2 = $this->createVm('vm-m2');

        $this->service->allocateIp($pool, $vm1, $this->user); // .2 allocated
        $alloc2 = $this->service->allocateIp($pool, $vm2, $this->user); // .3 allocated
        $this->service->releaseIp($vm2); // .3 cooling

        $stats = $this->service->getPoolUtilization($pool);

        $this->assertEquals(4, $stats['total']);
        $this->assertEquals(1, $stats['allocated']);
        $this->assertEquals(1, $stats['cooling']);
        $this->assertEquals(2, $stats['free']);
        $this->assertEquals(50.0, $stats['percent']);
    }

    public function test_create_and_delete_pool_via_service(): void
    {
        $dto = new CreateIpPoolDTO(
            name: 'DTO Test Pool',
            cidr: '10.50.0.0/28',
            hostId: $this->host->id,
            networkMode: 'routed',
            parentInterface: 'ens3',
        );

        $pool = $this->service->createPool($dto);

        $this->assertEquals('10.50.0.0/28', $pool->cidr);
        $this->assertEquals('10.50.0.1', $pool->first_usable);
        $this->assertEquals('10.50.0.14', $pool->last_usable);
        $this->assertEquals('ens3', $pool->parent_interface);

        // Delete empty pool succeeds
        $this->service->deletePool($pool);
        $this->assertDatabaseMissing('ip_pools', ['id' => $pool->id]);
    }

    public function test_prune_expired_cooling_allocations(): void
    {
        $pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Prune Test Pool',
            'cidr' => '192.168.70.0/29',
            'first_usable' => '192.168.70.1',
            'last_usable' => '192.168.70.5',
            'network_mode' => 'routed',
            'cooldown_seconds' => 60,
        ]);

        $vm1 = $this->createVm('vm-p1');
        $vm2 = $this->createVm('vm-p2');

        $alloc1 = $this->service->allocateIp($pool, $vm1, $this->user);
        $alloc2 = $this->service->allocateIp($pool, $vm2, $this->user);

        $this->service->releaseIp($vm1);
        $this->service->releaseIp($vm2);

        // Make alloc1 expired (released 120s ago), alloc2 still fresh (released now)
        $alloc1->update(['released_at' => now()->subSeconds(120)]);

        $pruned = $this->service->pruneExpiredCoolingAllocations();
        $this->assertEquals(1, $pruned);

        $this->assertDatabaseMissing('ip_allocations', ['id' => $alloc1->id]);
        $this->assertDatabaseHas('ip_allocations', ['id' => $alloc2->id]);
    }
}
