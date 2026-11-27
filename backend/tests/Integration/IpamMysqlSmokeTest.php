<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Enums\IpAllocationStatus;
use App\Models\Host;
use App\Models\IpAllocation;
use App\Models\IpPool;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\IpManagementService;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

test('IPAM ordering by (pool_id, address) is numeric and allocateIp updateOrCreate reuses existing row on MySQL', function (): void {
    skipUnlessRealInfrastructure();

    $suffix = uniqid();
    $host = Host::create([
        'name' => 'ipam-order-host-'.$suffix,
        'endpoint_url' => 'https://127.0.0.1:8443',
        'driver' => 'fake',
        'is_active' => true,
    ]);

    $pool1 = IpPool::create([
        'host_id' => $host->id,
        'name' => 'IPAM Order Pool 1 '.$suffix,
        'cidr' => '10.0.0.0/24',
        'first_usable' => '10.0.0.2',
        'last_usable' => '10.0.0.200',
        'network_mode' => 'routed',
        'parent_interface' => 'eth0',
        'cooldown_seconds' => 60,
        'active' => true,
        'is_public' => true,
    ]);

    $pool2 = IpPool::create([
        'host_id' => $host->id,
        'name' => 'IPAM Order Pool 2 '.$suffix,
        'cidr' => '10.0.1.0/24',
        'first_usable' => '10.0.1.2',
        'last_usable' => '10.0.1.200',
        'network_mode' => 'routed',
        'parent_interface' => 'eth0',
        'cooldown_seconds' => 60,
        'active' => true,
        'is_public' => true,
    ]);

    $user = User::factory()->create();
    $vm1 = VirtualMachine::create([
        'user_id' => $user->id,
        'host_id' => $host->id,
        'name' => 'ipam-reuse-vm1-'.$suffix,
        'incus_project' => 'default',
        'instance_type' => 'virtual-machine',
        'status' => 'running',
        'cpu_cores' => 1,
        'ram_mb' => 1024,
        'disk_gb' => 10,
        'os_image' => 'ubuntu:24.04',
    ]);
    $vm2 = VirtualMachine::create([
        'user_id' => $user->id,
        'host_id' => $host->id,
        'name' => 'ipam-reuse-vm2-'.$suffix,
        'incus_project' => 'default',
        'instance_type' => 'virtual-machine',
        'status' => 'running',
        'cpu_cores' => 1,
        'ram_mb' => 1024,
        'disk_gb' => 10,
        'os_image' => 'ubuntu:24.04',
    ]);

    try {
        /** @var IpManagementService $service */
        $service = app(IpManagementService::class);

        // Verify allocateIp stores 4-byte inet_pton in varbinary(16) and updateOrCreate matches existing rows
        $alloc1 = $service->allocateIp($pool1, $vm1, $user);
        expect($alloc1->ip_string)->toBe('10.0.0.2');

        $hex = DB::table('ip_allocations')
            ->where('id', $alloc1->id)
            ->selectRaw('HEX(address) as hex_addr, LENGTH(address) as byte_len')
            ->first();
        expect($hex)->not->toBeNull();
        expect(strtoupper((string) $hex->hex_addr))->toBe('0A000002');
        expect((int) $hex->byte_len)->toBe(4);

        $service->releaseIp($vm1);
        IpAllocation::where('id', $alloc1->id)->update(['released_at' => now()->subSeconds(120)]);

        $reused = $service->allocateIp($pool1, $vm2, $user);
        expect($reused->id)->toBe($alloc1->id);
        expect($reused->ip_string)->toBe('10.0.0.2');
        expect($reused->status)->toBe(IpAllocationStatus::Allocated);

        // Insert remaining out-of-order IPs across both pools
        foreach ([
            [$pool2->id, '10.0.1.20'],
            [$pool1->id, '10.0.0.100'],
            [$pool1->id, '10.0.0.9'],
            [$pool2->id, '10.0.1.3'],
            [$pool1->id, '10.0.0.10'],
        ] as [$poolId, $ip]) {
            $raw = inet_pton($ip);
            expect($raw)->not->toBeFalse();

            IpAllocation::create([
                'pool_id' => $poolId,
                'user_id' => $user->id,
                'address' => $raw,
                'ip_string' => $ip,
                'status' => 'allocated',
                'allocated_at' => now(),
            ]);
        }

        $listed = $service->listForUser($user);
        expect($listed->pluck('ip_string')->all())->toBe([
            '10.0.0.2',
            '10.0.0.9',
            '10.0.0.10',
            '10.0.0.100',
            '10.0.1.3',
            '10.0.1.20',
        ]);
    } finally {
        IpAllocation::whereIn('pool_id', [$pool1->id, $pool2->id])->delete();
        $vm1->delete();
        $vm2->delete();
        $pool1->delete();
        $pool2->delete();
        $host->delete();
        $user->delete();
    }
})->group('integration');

test('IPAM pruneExpiredCoolingAllocations deletes expired rows with cutoff bindings on MySQL', function (): void {
    skipUnlessRealInfrastructure();

    $suffix = uniqid();
    $host = Host::create([
        'name' => 'ipam-prune-host-'.$suffix,
        'endpoint_url' => 'https://127.0.0.1:8443',
        'driver' => 'fake',
        'is_active' => true,
    ]);

    $shortPool = IpPool::create([
        'host_id' => $host->id,
        'name' => 'Prune Short '.$suffix,
        'cidr' => '10.40.1.0/24',
        'first_usable' => '10.40.1.1',
        'last_usable' => '10.40.1.50',
        'network_mode' => 'routed',
        'parent_interface' => 'eth0',
        'cooldown_seconds' => 30,
        'active' => true,
        'is_public' => true,
    ]);

    $longPool = IpPool::create([
        'host_id' => $host->id,
        'name' => 'Prune Long '.$suffix,
        'cidr' => '10.40.2.0/24',
        'first_usable' => '10.40.2.1',
        'last_usable' => '10.40.2.50',
        'network_mode' => 'routed',
        'parent_interface' => 'eth0',
        'cooldown_seconds' => 600,
        'active' => true,
        'is_public' => true,
    ]);

    $user = User::factory()->create();

    try {
        $shortExpired = IpAllocation::create([
            'pool_id' => $shortPool->id,
            'user_id' => $user->id,
            'address' => inet_pton('10.40.1.1'),
            'ip_string' => '10.40.1.1',
            'status' => 'cooling',
            'allocated_at' => now()->subMinutes(10),
            'released_at' => now()->subSeconds(60),
        ]);
        $shortFresh = IpAllocation::create([
            'pool_id' => $shortPool->id,
            'user_id' => $user->id,
            'address' => inet_pton('10.40.1.2'),
            'ip_string' => '10.40.1.2',
            'status' => 'cooling',
            'allocated_at' => now()->subMinutes(10),
            'released_at' => now()->subSeconds(10),
        ]);
        $shortAllocated = IpAllocation::create([
            'pool_id' => $shortPool->id,
            'user_id' => $user->id,
            'address' => inet_pton('10.40.1.3'),
            'ip_string' => '10.40.1.3',
            'status' => 'allocated',
            'allocated_at' => now()->subMinutes(10),
            'released_at' => null,
        ]);
        $longFresh = IpAllocation::create([
            'pool_id' => $longPool->id,
            'user_id' => $user->id,
            'address' => inet_pton('10.40.2.1'),
            'ip_string' => '10.40.2.1',
            'status' => 'cooling',
            'allocated_at' => now()->subMinutes(10),
            'released_at' => now()->subSeconds(120),
        ]);
        $longExpired = IpAllocation::create([
            'pool_id' => $longPool->id,
            'user_id' => $user->id,
            'address' => inet_pton('10.40.2.2'),
            'ip_string' => '10.40.2.2',
            'status' => 'cooling',
            'allocated_at' => now()->subMinutes(20),
            'released_at' => now()->subSeconds(700),
        ]);

        /** @var IpManagementService $service */
        $service = app(IpManagementService::class);

        $prunedShort = $service->pruneExpiredCoolingAllocations($shortPool->id);
        expect($prunedShort)->toBe(1);
        expect(IpAllocation::find($shortExpired->id))->toBeNull();
        expect(IpAllocation::find($shortFresh->id))->not->toBeNull();
        expect(IpAllocation::find($shortAllocated->id))->not->toBeNull();
        expect(IpAllocation::find($longExpired->id))->not->toBeNull();

        $prunedAll = $service->pruneExpiredCoolingAllocations();
        expect($prunedAll)->toBe(1);
        expect(IpAllocation::find($longExpired->id))->toBeNull();
        expect(IpAllocation::find($longFresh->id))->not->toBeNull();
    } finally {
        IpAllocation::whereIn('pool_id', [$shortPool->id, $longPool->id])->delete();
        $shortPool->delete();
        $longPool->delete();
        $host->delete();
        $user->delete();
    }
})->group('integration');

test('IPAM attachUtilizationToPools aggregates allocated and cooling counts accurately on MySQL', function (): void {
    skipUnlessRealInfrastructure();

    $suffix = uniqid();
    $host = Host::create([
        'name' => 'ipam-util-host-'.$suffix,
        'endpoint_url' => 'https://127.0.0.1:8443',
        'driver' => 'fake',
        'is_active' => true,
    ]);

    $pool1 = IpPool::create([
        'host_id' => $host->id,
        'name' => 'Util Pool 1 '.$suffix,
        'cidr' => '10.50.1.0/29',
        'first_usable' => '10.50.1.1',
        'last_usable' => '10.50.1.5',
        'gateway' => '10.50.1.1',
        'network_mode' => 'routed',
        'parent_interface' => 'eth0',
        'cooldown_seconds' => 120,
        'active' => true,
        'is_public' => true,
    ]);

    $pool2 = IpPool::create([
        'host_id' => $host->id,
        'name' => 'Util Pool 2 '.$suffix,
        'cidr' => '10.50.2.0/28',
        'first_usable' => '10.50.2.1',
        'last_usable' => '10.50.2.12',
        'network_mode' => 'routed',
        'parent_interface' => 'eth0',
        'cooldown_seconds' => 60,
        'active' => true,
        'is_public' => true,
    ]);

    $user = User::factory()->create();

    try {
        IpAllocation::create([
            'pool_id' => $pool1->id,
            'user_id' => $user->id,
            'address' => inet_pton('10.50.1.2'),
            'ip_string' => '10.50.1.2',
            'status' => 'allocated',
            'allocated_at' => now()->subMinutes(5),
        ]);
        IpAllocation::create([
            'pool_id' => $pool1->id,
            'user_id' => $user->id,
            'address' => inet_pton('10.50.1.3'),
            'ip_string' => '10.50.1.3',
            'status' => 'cooling',
            'allocated_at' => now()->subMinutes(5),
            'released_at' => now()->subSeconds(30),
        ]);
        // Expired cooling row must not be counted as cooling
        IpAllocation::create([
            'pool_id' => $pool1->id,
            'user_id' => $user->id,
            'address' => inet_pton('10.50.1.4'),
            'ip_string' => '10.50.1.4',
            'status' => 'cooling',
            'allocated_at' => now()->subMinutes(10),
            'released_at' => now()->subSeconds(300),
        ]);
        IpAllocation::create([
            'pool_id' => $pool2->id,
            'user_id' => $user->id,
            'address' => inet_pton('10.50.2.1'),
            'ip_string' => '10.50.2.1',
            'status' => 'allocated',
            'allocated_at' => now()->subMinutes(2),
        ]);
        IpAllocation::create([
            'pool_id' => $pool2->id,
            'user_id' => $user->id,
            'address' => inet_pton('10.50.2.2'),
            'ip_string' => '10.50.2.2',
            'status' => 'allocated',
            'allocated_at' => now()->subMinutes(2),
        ]);

        /** @var IpManagementService $service */
        $service = app(IpManagementService::class);
        $service->attachUtilizationToPools([$pool1, $pool2]);

        expect($pool1->utilization)->toBe([
            'total' => 4,
            'allocated' => 1,
            'cooling' => 1,
            'free' => 2,
            'percent' => 50.0,
        ]);
        expect($pool2->utilization)->toBe([
            'total' => 12,
            'allocated' => 2,
            'cooling' => 0,
            'free' => 10,
            'percent' => 16.7,
        ]);

        expect($pool1->utilization)->toBe($service->getPoolUtilization($pool1));
        expect($pool2->utilization)->toBe($service->getPoolUtilization($pool2));
    } finally {
        IpAllocation::whereIn('pool_id', [$pool1->id, $pool2->id])->delete();
        $pool1->delete();
        $pool2->delete();
        $host->delete();
        $user->delete();
    }
})->group('integration');

test('IPAM allocation search prefix LIKE and wildcard escaping work on MySQL', function (): void {
    skipUnlessRealInfrastructure();

    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

    $suffix = uniqid();
    $admin = User::factory()->create(['name' => 'Admin Search '.$suffix, 'email' => "admin-{$suffix}@test.local"]);
    $admin->assignRole('admin');

    $alice = User::factory()->create(['name' => 'Alice Search '.$suffix, 'email' => "alice-{$suffix}@example.com"]);
    $literalUser = User::factory()->create(['name' => "user_a_b%c_{$suffix}", 'email' => "lit-{$suffix}@example.com"]);
    $similarUser = User::factory()->create(['name' => "user_axbyc_{$suffix}", 'email' => "sim-{$suffix}@example.com"]);

    $host = Host::create([
        'name' => 'ipam-search-host-'.$suffix,
        'endpoint_url' => 'https://127.0.0.1:8443',
        'driver' => 'fake',
        'is_active' => true,
    ]);

    $poolMain = IpPool::create([
        'host_id' => $host->id,
        'name' => 'Search Main '.$suffix,
        'cidr' => '10.60.0.0/16',
        'first_usable' => '10.60.0.1',
        'last_usable' => '10.60.255.254',
        'network_mode' => 'routed',
        'parent_interface' => 'eth0',
        'active' => true,
        'is_public' => true,
    ]);

    $poolOther = IpPool::create([
        'host_id' => $host->id,
        'name' => 'Search Other '.$suffix,
        'cidr' => '110.60.0.0/24',
        'first_usable' => '110.60.0.1',
        'last_usable' => '110.60.0.254',
        'network_mode' => 'routed',
        'parent_interface' => 'eth0',
        'active' => true,
        'is_public' => true,
    ]);

    $vmLiteral = VirtualMachine::create([
        'user_id' => $literalUser->id,
        'host_id' => $host->id,
        'name' => 'vm\\back-'.$suffix,
        'incus_project' => 'default',
        'instance_type' => 'virtual-machine',
        'status' => 'running',
        'cpu_cores' => 1,
        'ram_mb' => 1024,
        'disk_gb' => 10,
        'os_image' => 'ubuntu:24.04',
    ]);

    try {
        foreach ([
            [$poolMain->id, $alice->id, null, '10.60.0.1', '02:54:ab:00:00:01'],
            [$poolMain->id, $alice->id, null, '10.60.0.10', '02:54:ab:00:00:02'],
            [$poolMain->id, $alice->id, null, '10.60.0.100', '02:54:99:02:54:ab'],
            [$poolMain->id, $literalUser->id, $vmLiteral->id, '10.60.1.1', '02:54:cc:00:00:01'],
            [$poolOther->id, $similarUser->id, null, '110.60.0.1', '02:54:dd:00:00:01'],
        ] as [$poolId, $userId, $vmId, $ip, $mac]) {
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

        /** @var IpManagementService $service */
        $service = app(IpManagementService::class);

        expect($service->listForUser($admin, ['search' => ' 10.60.0.1 '])->pluck('ip_string')->all())
            ->toBe(['10.60.0.1', '10.60.0.10', '10.60.0.100']);

        expect($service->listForUser($admin, ['search' => '10.60'])->pluck('ip_string')->all())
            ->toBe(['10.60.0.1', '10.60.0.10', '10.60.0.100', '10.60.1.1']);

        expect($service->listForUser($admin, ['search' => '02:54:ab'])->pluck('ip_string')->all())
            ->toBe(['10.60.0.1', '10.60.0.10']);

        expect($service->listForUser($admin, ['search' => '02:54:AB'])->pluck('ip_string')->all())
            ->toBe(['10.60.0.1', '10.60.0.10']);

        // Escaped LIKE wildcards (_, %, \) must match literally on MySQL
        expect($service->listForUser($admin, ['search' => 'a_b%'])->pluck('ip_string')->all())
            ->toBe(['10.60.1.1']);

        expect($service->listForUser($admin, ['search' => 'vm\\back-'.$suffix])->pluck('ip_string')->all())
            ->toBe(['10.60.1.1']);

        expect($service->listForUser($admin, ['search' => 'Alice Search '.$suffix])->pluck('ip_string')->all())
            ->toBe(['10.60.0.1', '10.60.0.10', '10.60.0.100']);
    } finally {
        IpAllocation::whereIn('pool_id', [$poolMain->id, $poolOther->id])->delete();
        $vmLiteral->delete();
        $poolMain->delete();
        $poolOther->delete();
        $host->delete();
        $alice->delete();
        $literalUser->delete();
        $similarUser->delete();
        $admin->delete();
    }
})->group('integration');
