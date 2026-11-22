<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTO\Network\CreateIpPoolDTO;
use App\Exceptions\IpPoolExhaustedException;
use App\Exceptions\PoolAllocationConflictException;
use App\Exceptions\PoolHasActiveAllocationsException;
use App\Models\Host;
use App\Models\IpAllocation;
use App\Models\IpPool;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\IpManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
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

    public function test_prune_expired_cooling_allocations_respects_per_pool_cooldown_allocated_rows_and_pool_filter(): void
    {
        $shortPool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Short Cooldown Pool',
            'cidr' => '192.168.71.0/29',
            'first_usable' => '192.168.71.1',
            'last_usable' => '192.168.71.5',
            'network_mode' => 'routed',
            'cooldown_seconds' => 30,
        ]);

        $longPool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Long Cooldown Pool',
            'cidr' => '192.168.72.0/29',
            'first_usable' => '192.168.72.1',
            'last_usable' => '192.168.72.5',
            'network_mode' => 'routed',
            'cooldown_seconds' => 600,
        ]);

        $zeroPool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Zero Cooldown Pool',
            'cidr' => '192.168.73.0/29',
            'first_usable' => '192.168.73.1',
            'last_usable' => '192.168.73.5',
            'network_mode' => 'routed',
            'cooldown_seconds' => 0,
        ]);

        // Short pool: expired cooling (60s > 30s), active cooling (10s < 30s), allocated row
        $shortExpired = IpAllocation::create([
            'pool_id' => $shortPool->id,
            'user_id' => $this->user->id,
            'address' => inet_pton('192.168.71.1'),
            'ip_string' => '192.168.71.1',
            'status' => 'cooling',
            'allocated_at' => now()->subMinutes(10),
            'released_at' => now()->subSeconds(60),
        ]);
        $shortFresh = IpAllocation::create([
            'pool_id' => $shortPool->id,
            'user_id' => $this->user->id,
            'address' => inet_pton('192.168.71.2'),
            'ip_string' => '192.168.71.2',
            'status' => 'cooling',
            'allocated_at' => now()->subMinutes(10),
            'released_at' => now()->subSeconds(10),
        ]);
        $shortAllocated = IpAllocation::create([
            'pool_id' => $shortPool->id,
            'user_id' => $this->user->id,
            'address' => inet_pton('192.168.71.3'),
            'ip_string' => '192.168.71.3',
            'status' => 'allocated',
            'allocated_at' => now()->subMinutes(10),
            'released_at' => null,
        ]);

        // Long pool: cooling released 120s ago (< 600s, must remain) and 700s ago (> 600s, expired)
        $longNotExpired = IpAllocation::create([
            'pool_id' => $longPool->id,
            'user_id' => $this->user->id,
            'address' => inet_pton('192.168.72.1'),
            'ip_string' => '192.168.72.1',
            'status' => 'cooling',
            'allocated_at' => now()->subMinutes(20),
            'released_at' => now()->subSeconds(120),
        ]);
        $longExpired = IpAllocation::create([
            'pool_id' => $longPool->id,
            'user_id' => $this->user->id,
            'address' => inet_pton('192.168.72.2'),
            'ip_string' => '192.168.72.2',
            'status' => 'cooling',
            'allocated_at' => now()->subMinutes(20),
            'released_at' => now()->subSeconds(700),
        ]);

        // Zero cooldown pool: cooling released 1s ago (or now) must be pruned
        $zeroExpired = IpAllocation::create([
            'pool_id' => $zeroPool->id,
            'user_id' => $this->user->id,
            'address' => inet_pton('192.168.73.1'),
            'ip_string' => '192.168.73.1',
            'status' => 'cooling',
            'allocated_at' => now()->subMinutes(5),
            'released_at' => now()->subSecond(),
        ]);

        // Prune only shortPool first
        $prunedShort = $this->service->pruneExpiredCoolingAllocations($shortPool->id);
        $this->assertSame(1, $prunedShort);
        $this->assertDatabaseMissing('ip_allocations', ['id' => $shortExpired->id]);
        $this->assertDatabaseHas('ip_allocations', ['id' => $shortFresh->id]);
        $this->assertDatabaseHas('ip_allocations', ['id' => $shortAllocated->id]);
        $this->assertDatabaseHas('ip_allocations', ['id' => $longNotExpired->id]);
        $this->assertDatabaseHas('ip_allocations', ['id' => $longExpired->id]);
        $this->assertDatabaseHas('ip_allocations', ['id' => $zeroExpired->id]);

        // Prune all pools: should delete longExpired and zeroExpired, keeping longNotExpired
        $prunedAll = $this->service->pruneExpiredCoolingAllocations();
        $this->assertSame(2, $prunedAll);
        $this->assertDatabaseMissing('ip_allocations', ['id' => $longExpired->id]);
        $this->assertDatabaseMissing('ip_allocations', ['id' => $zeroExpired->id]);
        $this->assertDatabaseHas('ip_allocations', ['id' => $longNotExpired->id]);
        $this->assertDatabaseHas('ip_allocations', ['id' => $shortFresh->id]);
        $this->assertDatabaseHas('ip_allocations', ['id' => $shortAllocated->id]);
    }

    public function test_attach_utilization_to_pools_matches_single_pool_computation_across_varied_pools(): void
    {
        // Pool 1: /29 (1..6 = 6), gateway .1 inside range + excluded .2 => 4 usable.
        // 1 allocated (.3), 1 active cooling (.4), 1 expired cooling (.5).
        $pool1 = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Pool One',
            'cidr' => '10.10.1.0/29',
            'first_usable' => '10.10.1.1',
            'last_usable' => '10.10.1.6',
            'gateway' => '10.10.1.1',
            'excluded_ips' => ['10.10.1.2'],
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
            'cooldown_seconds' => 120,
        ]);

        IpAllocation::create([
            'pool_id' => $pool1->id,
            'user_id' => $this->user->id,
            'address' => inet_pton('10.10.1.3'),
            'ip_string' => '10.10.1.3',
            'status' => 'allocated',
            'allocated_at' => now(),
        ]);
        IpAllocation::create([
            'pool_id' => $pool1->id,
            'user_id' => $this->user->id,
            'address' => inet_pton('10.10.1.4'),
            'ip_string' => '10.10.1.4',
            'status' => 'cooling',
            'allocated_at' => now()->subMinutes(5),
            'released_at' => now()->subSeconds(30),
        ]);
        IpAllocation::create([
            'pool_id' => $pool1->id,
            'user_id' => $this->user->id,
            'address' => inet_pton('10.10.1.5'),
            'ip_string' => '10.10.1.5',
            'status' => 'cooling',
            'allocated_at' => now()->subMinutes(10),
            'released_at' => now()->subSeconds(200),
        ]);

        // Pool 2: /28 (1..14 = 14), gateway outside range (.254), 2 excluded (.5, .6) => 12 usable.
        // 2 allocated, 0 cooling.
        $pool2 = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Pool Two',
            'cidr' => '10.10.2.0/28',
            'first_usable' => '10.10.2.1',
            'last_usable' => '10.10.2.14',
            'gateway' => '10.10.2.254',
            'excluded_ips' => ['10.10.2.5', '10.10.2.6'],
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
            'cooldown_seconds' => 300,
        ]);

        IpAllocation::create([
            'pool_id' => $pool2->id,
            'user_id' => $this->user->id,
            'address' => inet_pton('10.10.2.1'),
            'ip_string' => '10.10.2.1',
            'status' => 'allocated',
            'allocated_at' => now(),
        ]);
        IpAllocation::create([
            'pool_id' => $pool2->id,
            'user_id' => $this->user->id,
            'address' => inet_pton('10.10.2.2'),
            'ip_string' => '10.10.2.2',
            'status' => 'allocated',
            'allocated_at' => now(),
        ]);

        // Pool 3: /30 (1..2 = 2), no gateway, no excluded => 2 usable.
        // 0 allocated, 2 active cooling (100% utilized), plus 0s cooldown pool case tested via 600s cooldown.
        $pool3 = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Pool Three',
            'cidr' => '10.10.3.0/30',
            'first_usable' => '10.10.3.1',
            'last_usable' => '10.10.3.2',
            'network_mode' => 'manual',
            'parent_interface' => '',
            'cooldown_seconds' => 600,
        ]);

        IpAllocation::create([
            'pool_id' => $pool3->id,
            'user_id' => $this->user->id,
            'address' => inet_pton('10.10.3.1'),
            'ip_string' => '10.10.3.1',
            'status' => 'cooling',
            'allocated_at' => now()->subMinutes(2),
            'released_at' => now()->subSeconds(50),
        ]);
        IpAllocation::create([
            'pool_id' => $pool3->id,
            'user_id' => $this->user->id,
            'address' => inet_pton('10.10.3.2'),
            'ip_string' => '10.10.3.2',
            'status' => 'cooling',
            'allocated_at' => now()->subMinutes(2),
            'released_at' => now()->subSeconds(100),
        ]);

        $this->service->attachUtilizationToPools([$pool1, $pool2, $pool3]);

        $expected1 = [
            'total' => 4,
            'allocated' => 1,
            'cooling' => 1,
            'free' => 2,
            'percent' => 50.0,
        ];
        $expected2 = [
            'total' => 12,
            'allocated' => 2,
            'cooling' => 0,
            'free' => 10,
            'percent' => 16.7,
        ];
        $expected3 = [
            'total' => 2,
            'allocated' => 0,
            'cooling' => 2,
            'free' => 0,
            'percent' => 100.0,
        ];

        $this->assertSame($expected1, $pool1->utilization);
        $this->assertSame($expected2, $pool2->utilization);
        $this->assertSame($expected3, $pool3->utilization);

        $this->assertSame($this->service->getPoolUtilization($pool1), $pool1->utilization);
        $this->assertSame($this->service->getPoolUtilization($pool2), $pool2->utilization);
        $this->assertSame($this->service->getPoolUtilization($pool3), $pool3->utilization);
    }

    public function test_attach_utilization_to_pools_query_count_is_constant_for_3_vs_10_pools(): void
    {
        $pools = [];
        for ($i = 1; $i <= 10; $i++) {
            $pool = IpPool::create([
                'host_id' => $this->host->id,
                'name' => 'Batch Pool '.$i,
                'cidr' => "10.20.{$i}.0/24",
                'first_usable' => "10.20.{$i}.1",
                'last_usable' => "10.20.{$i}.50",
                'network_mode' => 'routed',
                'parent_interface' => 'eth0',
                'cooldown_seconds' => 120,
            ]);

            for ($j = 1; $j <= $i; $j++) {
                IpAllocation::create([
                    'pool_id' => $pool->id,
                    'user_id' => $this->user->id,
                    'address' => inet_pton("10.20.{$i}.{$j}"),
                    'ip_string' => "10.20.{$i}.{$j}",
                    'status' => $j % 2 === 0 ? 'cooling' : 'allocated',
                    'allocated_at' => now()->subMinutes(5),
                    'released_at' => $j % 2 === 0 ? now()->subSeconds(30) : null,
                ]);
            }

            $pools[] = $pool;
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->service->attachUtilizationToPools(array_slice($pools, 0, 3));
        $queriesFor3 = DB::getQueryLog();

        DB::flushQueryLog();
        $this->service->attachUtilizationToPools($pools);
        $queriesFor10 = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(2, $queriesFor3);
        $this->assertSameSize($queriesFor3, $queriesFor10);

        foreach ($queriesFor10 as $entry) {
            $this->assertStringNotContainsString('ip_pools', strtolower((string) $entry['query']));
        }
    }

    public function test_allocations_are_ordered_by_pool_id_and_numeric_ipv4_address(): void
    {
        $pool1 = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Order Pool 1',
            'cidr' => '10.0.0.0/24',
            'first_usable' => '10.0.0.1',
            'last_usable' => '10.0.0.200',
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
        ]);

        $pool2 = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Order Pool 2',
            'cidr' => '10.0.1.0/24',
            'first_usable' => '10.0.1.1',
            'last_usable' => '10.0.1.200',
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
        ]);

        // Insert shuffled across both pools to verify pool_id grouping + byte-wise numeric IPv4 order
        $inserts = [
            [$pool2->id, '10.0.1.20'],
            [$pool1->id, '10.0.0.100'],
            [$pool1->id, '10.0.0.9'],
            [$pool2->id, '10.0.1.3'],
            [$pool1->id, '10.0.0.10'],
            [$pool1->id, '10.0.0.2'],
        ];

        foreach ($inserts as [$poolId, $ip]) {
            IpAllocation::create([
                'pool_id' => $poolId,
                'user_id' => $this->user->id,
                'address' => inet_pton($ip),
                'ip_string' => $ip,
                'status' => 'allocated',
                'allocated_at' => now(),
            ]);
        }

        $listed = $this->service->listForUser($this->user);
        $this->assertSame(
            ['10.0.0.2', '10.0.0.9', '10.0.0.10', '10.0.0.100', '10.0.1.3', '10.0.1.20'],
            $listed->pluck('ip_string')->all()
        );

        $paginated = $this->service->paginateForUser($this->user, [], 10);
        $this->assertSame(
            ['10.0.0.2', '10.0.0.9', '10.0.0.10', '10.0.0.100', '10.0.1.3', '10.0.1.20'],
            collect($paginated->items())->pluck('ip_string')->all()
        );
    }

    public function test_host_filter_returns_allocations_only_for_pools_on_specified_host(): void
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $host2 = Host::create([
            'name' => 'compute-node-de',
            'endpoint_url' => 'https://127.0.0.2:8443',
            'driver' => 'fake',
            'is_active' => true,
            'total_cpu_cores' => 16,
            'total_ram_mb' => 32768,
            'total_storage_gb' => 500,
        ]);

        $poolHost1 = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Host 1 Pool',
            'cidr' => '10.30.1.0/24',
            'first_usable' => '10.30.1.1',
            'last_usable' => '10.30.1.50',
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
        ]);

        $poolHost2 = IpPool::create([
            'host_id' => $host2->id,
            'name' => 'Host 2 Pool',
            'cidr' => '10.30.2.0/24',
            'first_usable' => '10.30.2.1',
            'last_usable' => '10.30.2.50',
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
        ]);

        IpAllocation::create([
            'pool_id' => $poolHost1->id,
            'user_id' => $this->user->id,
            'address' => inet_pton('10.30.1.5'),
            'ip_string' => '10.30.1.5',
            'status' => 'allocated',
            'allocated_at' => now(),
        ]);
        IpAllocation::create([
            'pool_id' => $poolHost2->id,
            'user_id' => $this->user->id,
            'address' => inet_pton('10.30.2.10'),
            'ip_string' => '10.30.2.10',
            'status' => 'allocated',
            'allocated_at' => now(),
        ]);
        IpAllocation::create([
            'pool_id' => $poolHost2->id,
            'user_id' => $this->user->id,
            'address' => inet_pton('10.30.2.2'),
            'ip_string' => '10.30.2.2',
            'status' => 'cooling',
            'allocated_at' => now()->subMinutes(10),
            'released_at' => now()->subSeconds(30),
        ]);

        $host2Results = $this->service->listForUser($admin, ['host_id' => $host2->id]);
        $this->assertSame(['10.30.2.2', '10.30.2.10'], $host2Results->pluck('ip_string')->all());

        $host1Results = $this->service->paginateForUser($admin, ['host_id' => $this->host->id], 10);
        $this->assertSame(['10.30.1.5'], collect($host1Results->items())->pluck('ip_string')->all());
    }

    public function test_ip_allocations_status_indexes_migration_up_and_down(): void
    {
        $this->assertTrue(Schema::hasIndex('ip_allocations', 'idx_ip_alloc_user_id'));
        $this->assertTrue(Schema::hasIndex('ip_allocations', 'idx_ip_alloc_pool_status'));
        $this->assertTrue(Schema::hasIndex('ip_allocations', 'idx_ip_alloc_user_status'));

        $migration = require database_path('migrations/2026_11_19_000001_add_status_indexes_to_ip_allocations_table.php');
        $migration->down();

        $this->assertTrue(Schema::hasIndex('ip_allocations', 'idx_ip_alloc_user_id'));
        $this->assertFalse(Schema::hasIndex('ip_allocations', 'idx_ip_alloc_pool_status'));
        $this->assertFalse(Schema::hasIndex('ip_allocations', 'idx_ip_alloc_user_status'));

        $migration->up();

        $this->assertTrue(Schema::hasIndex('ip_allocations', 'idx_ip_alloc_user_id'));
        $this->assertTrue(Schema::hasIndex('ip_allocations', 'idx_ip_alloc_pool_status'));
        $this->assertTrue(Schema::hasIndex('ip_allocations', 'idx_ip_alloc_user_status'));
    }

    public function test_allocations_search_classifies_ip_prefix_mac_prefix_and_escaped_text(): void
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['name' => 'Admin Searcher', 'email' => 'search-admin@test.local']);
        $admin->assignRole('admin');

        $alice = User::factory()->create(['name' => 'Alice Example', 'email' => 'alice@example.com']);
        $literalWildcardUser = User::factory()->create(['name' => 'user_a_b%c', 'email' => 'literal@example.com']);
        $nonMatchingSimilarUser = User::factory()->create(['name' => 'user_axbyc', 'email' => 'similar@example.com']);

        $poolMain = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Primary Search Pool',
            'cidr' => '10.0.0.0/16',
            'first_usable' => '10.0.0.1',
            'last_usable' => '10.0.255.254',
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
        ]);

        $poolOther = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Secondary Search Pool',
            'cidr' => '110.0.0.0/24',
            'first_usable' => '110.0.0.1',
            'last_usable' => '110.0.0.254',
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
        ]);

        $vmLiteral = $this->createVm('vm\\backslash');

        $records = [
            [$poolMain->id, $alice->id, null, '10.0.0.1', '02:54:ab:00:00:01'],
            [$poolMain->id, $alice->id, null, '10.0.0.10', '02:54:ab:00:00:02'],
            [$poolMain->id, $alice->id, null, '10.0.0.100', '02:54:99:02:54:ab'],
            [$poolMain->id, $literalWildcardUser->id, $vmLiteral->id, '10.0.1.1', '02:54:cc:00:00:01'],
            [$poolOther->id, $nonMatchingSimilarUser->id, null, '110.0.0.1', '02:54:dd:00:00:01'],
        ];

        foreach ($records as [$poolId, $userId, $vmId, $ip, $mac]) {
            IpAllocation::create([
                'pool_id' => $poolId,
                'user_id' => $userId,
                'virtual_machine_id' => $vmId,
                'address' => inet_pton($ip),
                'ip_string' => $ip,
                'mac_address' => $mac,
                'status' => 'allocated',
                'allocated_at' => now(),
            ]);
        }

        // 1. IP prefix '10.0.0.1' (with whitespace) matches 10.0.0.1, 10.0.0.10, 10.0.0.100 only
        $ipExactPrefix = $this->service->listForUser($admin, ['search' => ' 10.0.0.1 ']);
        $this->assertSame(['10.0.0.1', '10.0.0.10', '10.0.0.100'], $ipExactPrefix->pluck('ip_string')->all());

        // 2. IP prefix '10.0' matches 10.0.x.x only, not 110.0.0.1
        $ipPartialPrefix = $this->service->listForUser($admin, ['search' => '10.0']);
        $this->assertSame(['10.0.0.1', '10.0.0.10', '10.0.0.100', '10.0.1.1'], $ipPartialPrefix->pluck('ip_string')->all());

        // 3. MAC prefix '02:54:ab' and uppercase '02:54:AB' match prefix only (not 02:54:99:02:54:ab)
        $macLower = $this->service->listForUser($admin, ['search' => '02:54:ab']);
        $this->assertSame(['10.0.0.1', '10.0.0.10'], $macLower->pluck('ip_string')->all());

        $macUpper = $this->service->listForUser($admin, ['search' => '02:54:AB']);
        $this->assertSame(['10.0.0.1', '10.0.0.10'], $macUpper->pluck('ip_string')->all());

        // 4. LIKE wildcard characters (_, %, \) are escaped
        $escapedWildcards = $this->service->listForUser($admin, ['search' => 'a_b%']);
        $this->assertSame(['10.0.1.1'], $escapedWildcards->pluck('ip_string')->all());

        $escapedBackslash = $this->service->listForUser($admin, ['search' => 'vm\\back']);
        $this->assertSame(['10.0.1.1'], $escapedBackslash->pluck('ip_string')->all());

        // 5. User name search still works
        $userSearch = $this->service->listForUser($admin, ['search' => 'Alice']);
        $this->assertSame(['10.0.0.1', '10.0.0.10', '10.0.0.100'], $userSearch->pluck('ip_string')->all());

        // 6. Empty and whitespace-only searches return all allocations
        $this->assertCount(5, $this->service->listForUser($admin, ['search' => '']));
        $this->assertCount(5, $this->service->listForUser($admin, ['search' => '   ']));
    }

    public function test_routed_pool_without_gateway_allocates_from_first_usable(): void
    {
        $pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Routed No Gateway Pool',
            'cidr' => '192.168.100.0/24',
            'first_usable' => '192.168.100.1',
            'last_usable' => '192.168.100.10',
            'gateway' => null,
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
        ]);

        $vm1 = $this->createVm('vm-nogw-1');
        $vm2 = $this->createVm('vm-nogw-2');

        $alloc1 = $this->service->allocateIp($pool, $vm1, $this->user);
        $alloc2 = $this->service->allocateIp($pool, $vm2, $this->user);

        $this->assertSame('192.168.100.1', $alloc1->ip_string);
        $this->assertSame('192.168.100.2', $alloc2->ip_string);
    }

    public function test_routed_pool_gateway_is_dynamically_excluded_without_persisting_in_excluded_ips(): void
    {
        $dto = new CreateIpPoolDTO(
            name: 'Routed Dynamic Exclusion Pool',
            cidr: '192.168.101.0/24',
            hostId: $this->host->id,
            firstUsable: '192.168.101.1',
            lastUsable: '192.168.101.10',
            gateway: '192.168.101.1',
            excludedIps: ['192.168.101.3'],
            networkMode: 'routed',
            parentInterface: 'eth0',
        );

        $pool = $this->service->createPool($dto);

        $this->assertSame(['192.168.101.3'], $pool->excluded_ips);
        $this->assertNotContains('192.168.101.1', $pool->excluded_ips ?? []);

        $vm = $this->createVm('vm-dyn-gw');
        $alloc = $this->service->allocateIp($pool, $vm, $this->user);

        $this->assertSame('192.168.101.2', $alloc->ip_string);

        $stats = $this->service->getPoolUtilization($pool->fresh());
        $this->assertSame(8, $stats['total']);
        $this->assertSame(1, $stats['allocated']);
        $this->assertSame(7, $stats['free']);
    }

    public function test_update_pool_changing_or_clearing_gateway_updates_allocation_availability(): void
    {
        $pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Gateway Update Pool',
            'cidr' => '192.168.102.0/29',
            'first_usable' => '192.168.102.1',
            'last_usable' => '192.168.102.3',
            'gateway' => '192.168.102.1',
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
        ]);

        // Change gateway from .1 to .2 -> .1 becomes free, .2 becomes dynamically excluded
        $pool = $this->service->updatePool($pool, ['gateway' => '192.168.102.2']);
        $this->assertSame('192.168.102.2', $pool->gateway);
        $this->assertSame([], $pool->excluded_ips ?? []);

        $vm1 = $this->createVm('vm-gw-upd-1');
        $vm2 = $this->createVm('vm-gw-upd-2');
        $alloc1 = $this->service->allocateIp($pool, $vm1, $this->user);
        $alloc2 = $this->service->allocateIp($pool, $vm2, $this->user);

        $this->assertSame('192.168.102.1', $alloc1->ip_string);
        $this->assertSame('192.168.102.3', $alloc2->ip_string);

        // Clear gateway -> .2 becomes immediately available
        $pool = $this->service->updatePool($pool, ['gateway' => null]);
        $this->assertNull($pool->gateway);

        $vm3 = $this->createVm('vm-gw-upd-3');
        $alloc3 = $this->service->allocateIp($pool, $vm3, $this->user);
        $this->assertSame('192.168.102.2', $alloc3->ip_string);
    }

    public function test_update_pool_targeting_allocated_ip_in_gateway_or_excluded_ips_throws_conflict_exception(): void
    {
        $pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Conflict Exception Pool',
            'cidr' => '192.168.103.0/24',
            'first_usable' => '192.168.103.1',
            'last_usable' => '192.168.103.20',
            'gateway' => '192.168.103.1',
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
        ]);

        $vm = $this->createVm('vm-conflict');
        $alloc = $this->service->allocateIp($pool, $vm, $this->user); // .2

        try {
            $this->service->updatePool($pool, ['gateway' => '192.168.103.2']);
            $this->fail('Expected PoolAllocationConflictException when setting gateway to an allocated IP.');
        } catch (PoolAllocationConflictException $e) {
            $this->assertSame($alloc->id, $e->allocationId);
            $this->assertSame('192.168.103.2', $e->ipAddress);
            $this->assertSame('gateway', $e->field);
        }

        try {
            $this->service->updatePool($pool, ['excluded_ips' => ['192.168.103.2']]);
            $this->fail('Expected PoolAllocationConflictException when adding an allocated IP to excluded_ips.');
        } catch (PoolAllocationConflictException $e) {
            $this->assertSame($alloc->id, $e->allocationId);
            $this->assertSame('192.168.103.2', $e->ipAddress);
            $this->assertSame('excluded_ips', $e->field);
        }
    }

    public function test_service_create_and_update_pool_validate_config_and_recalculate_range_on_cidr_change(): void
    {
        try {
            $this->service->createPool(new CreateIpPoolDTO(
                name: 'Invalid Bridged Without Gateway',
                cidr: '10.220.0.0/24',
                hostId: $this->host->id,
                networkMode: 'bridged',
                parentInterface: 'incusbr0',
            ));
            $this->fail('Expected ValidationException when creating a bridged pool without a gateway.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('gateway', $e->errors());
        }

        $pool = $this->service->createPool(new CreateIpPoolDTO(
            name: 'Recalculate CIDR Range Pool',
            cidr: '10.220.0.0/24',
            hostId: $this->host->id,
            gateway: '10.220.0.1',
            networkMode: 'routed',
            parentInterface: 'eth0',
        ));

        $this->assertSame('10.220.0.1', $pool->first_usable);
        $this->assertSame('10.220.0.254', $pool->last_usable);

        $updated = $this->service->updatePool($pool, [
            'cidr' => '10.221.0.0/28',
            'gateway' => '10.221.0.1',
        ]);

        $this->assertSame('10.221.0.0/28', $updated->cidr);
        $this->assertSame('10.221.0.1', $updated->first_usable);
        $this->assertSame('10.221.0.14', $updated->last_usable);
    }

    public function test_delete_pool_with_active_allocations_throws_pool_has_active_allocations_exception(): void
    {
        $pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Delete Active Pool',
            'cidr' => '192.168.104.0/29',
            'first_usable' => '192.168.104.1',
            'last_usable' => '192.168.104.6',
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
        ]);

        $vm = $this->createVm('vm-del-active');
        $this->service->allocateIp($pool, $vm, $this->user);

        try {
            $this->service->deletePool($pool);
            $this->fail('Expected PoolHasActiveAllocationsException.');
        } catch (PoolHasActiveAllocationsException $e) {
            $this->assertSame(422, $e->getCode());
        }

        $this->assertDatabaseHas('ip_pools', ['id' => $pool->id]);
    }

    public function test_utilization_does_not_double_count_excluded_address_that_is_allocated_or_cooling(): void
    {
        $pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Overlap Utilization Pool',
            'cidr' => '192.168.105.0/29',
            'first_usable' => '192.168.105.1',
            'last_usable' => '192.168.105.5', // 5 raw IPs: .1..5
            'gateway' => '192.168.105.1',
            'excluded_ips' => ['192.168.105.2'], // 2 excluded (.1, .2) -> 3 usable (.3, .4, .5)
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
            'cooldown_seconds' => 120,
        ]);

        // Excluded address .2 has an allocated row; usable address .3 is cooling
        IpAllocation::create([
            'pool_id' => $pool->id,
            'user_id' => $this->user->id,
            'address' => inet_pton('192.168.105.2'),
            'ip_string' => '192.168.105.2',
            'status' => 'allocated',
            'allocated_at' => now(),
        ]);

        IpAllocation::create([
            'pool_id' => $pool->id,
            'user_id' => $this->user->id,
            'address' => inet_pton('192.168.105.3'),
            'ip_string' => '192.168.105.3',
            'status' => 'cooling',
            'allocated_at' => now()->subMinutes(5),
            'released_at' => now()->subSeconds(10),
        ]);

        // Free addresses are .4 and .5 (2 free, not 3 - 2 = 1)
        $stats = $this->service->getPoolUtilization($pool);
        $this->assertSame(3, $stats['total']);
        $this->assertSame(1, $stats['allocated']);
        $this->assertSame(1, $stats['cooling']);
        $this->assertSame(2, $stats['free']);
        $this->assertSame(33.3, $stats['percent']);

        // Batch utilization path produces identical metrics
        $this->service->attachUtilizationToPools([$pool]);
        $this->assertSame($stats, $pool->utilization);
    }
}
