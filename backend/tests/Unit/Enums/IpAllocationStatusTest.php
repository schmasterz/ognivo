<?php

declare(strict_types=1);

use App\Enums\IpAllocationStatus;
use App\Http\Resources\IpAllocationResource;
use App\Models\Host;
use App\Models\IpAllocation;
use App\Models\IpPool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('defines all expected IpAllocationStatus cases and string values', function (): void {
    expect(array_map(static fn (IpAllocationStatus $status): string => $status->value, IpAllocationStatus::cases()))
        ->toBe([
            'allocated',
            'cooling',
        ]);
});

it('casts IpAllocation status to IpAllocationStatus enum', function (): void {
    $host = Host::create([
        'name' => 'enum-ip-host',
        'endpoint_url' => 'https://127.0.0.1:8443',
        'driver' => 'fake',
        'is_active' => true,
    ]);
    $pool = IpPool::create([
        'host_id' => $host->id,
        'name' => 'Enum Pool',
        'cidr' => '10.99.0.0/28',
        'first_usable' => '10.99.0.1',
        'last_usable' => '10.99.0.14',
        'network_mode' => 'routed',
    ]);
    $user = User::factory()->create();

    $allocated = IpAllocation::factory()->create([
        'pool_id' => $pool->id,
        'user_id' => $user->id,
        'address' => inet_pton('10.99.0.2'),
        'ip_string' => '10.99.0.2',
        'status' => IpAllocationStatus::Allocated,
    ]);

    $cooling = IpAllocation::factory()->create([
        'pool_id' => $pool->id,
        'user_id' => $user->id,
        'address' => inet_pton('10.99.0.3'),
        'ip_string' => '10.99.0.3',
        'status' => IpAllocationStatus::Cooling,
        'released_at' => now(),
    ]);

    expect($allocated->fresh()?->status)->toBe(IpAllocationStatus::Allocated)
        ->and($cooling->fresh()?->status)->toBe(IpAllocationStatus::Cooling)
        ->and(IpAllocation::allocated()->count())->toBe(1)
        ->and(IpAllocation::cooling()->count())->toBe(1);
});

it('serializes IpAllocationStatus to string value in IpAllocationResource', function (): void {
    $host = Host::create([
        'name' => 'enum-res-host',
        'endpoint_url' => 'https://127.0.0.1:8443',
        'driver' => 'fake',
        'is_active' => true,
    ]);
    $pool = IpPool::create([
        'host_id' => $host->id,
        'name' => 'Enum Resource Pool',
        'cidr' => '10.98.0.0/28',
        'first_usable' => '10.98.0.1',
        'last_usable' => '10.98.0.14',
        'network_mode' => 'routed',
        'cooldown_seconds' => 300,
    ]);
    $user = User::factory()->create();

    $allocated = IpAllocation::factory()->create([
        'pool_id' => $pool->id,
        'user_id' => $user->id,
        'address' => inet_pton('10.98.0.2'),
        'ip_string' => '10.98.0.2',
        'status' => IpAllocationStatus::Allocated,
    ]);

    $cooling = IpAllocation::factory()->create([
        'pool_id' => $pool->id,
        'user_id' => $user->id,
        'address' => inet_pton('10.98.0.3'),
        'ip_string' => '10.98.0.3',
        'status' => IpAllocationStatus::Cooling,
        'released_at' => now(),
    ]);

    $expiredCooling = IpAllocation::factory()->create([
        'pool_id' => $pool->id,
        'user_id' => $user->id,
        'address' => inet_pton('10.98.0.4'),
        'ip_string' => '10.98.0.4',
        'status' => IpAllocationStatus::Cooling,
        'released_at' => now()->subSeconds(600),
    ]);

    $request = Request::create('/');
    $request->setUserResolver(static fn (): User => $user);

    expect((new IpAllocationResource($allocated))->toArray($request)['status'])->toBe('allocated')
        ->and((new IpAllocationResource($cooling))->toArray($request)['status'])->toBe('cooling')
        ->and((new IpAllocationResource($expiredCooling))->toArray($request)['status'])->toBe('released');
});
