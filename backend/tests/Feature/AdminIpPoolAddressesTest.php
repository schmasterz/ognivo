<?php

declare(strict_types=1);

use App\Enums\NetworkStatus;
use App\Models\Host;
use App\Models\IpAllocation;
use App\Models\IpPool;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\IpManagementService;
use App\States\Vm\Running;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

    $this->admin = User::factory()->create(['email' => 'pool-addr-admin@test.local']);
    $this->admin->assignRole('admin');

    $this->tenant = User::factory()->create([
        'name' => 'Address Tenant',
        'email' => 'pool-addr-tenant@test.local',
    ]);
    $this->tenant->assignRole('customer');

    $this->host = Host::create([
        'name' => 'pool-addr-host',
        'endpoint_url' => 'https://127.0.0.1:8443',
        'driver' => 'fake',
        'is_active' => true,
    ]);

    $this->pool = IpPool::create([
        'host_id' => $this->host->id,
        'name' => 'Detail Address Pool',
        'cidr' => '192.168.10.0/24',
        'first_usable' => '192.168.10.1',
        'last_usable' => '192.168.10.15',
        'gateway' => '192.168.10.1',
        'excluded_ips' => ['192.168.10.1', '192.168.10.2', '192.168.10.15'],
        'network_mode' => 'routed',
        'parent_interface' => 'eth0',
        'cooldown_seconds' => 300,
        'is_public' => true,
        'active' => true,
    ]);

    $this->vm = VirtualMachine::create([
        'user_id' => $this->tenant->id,
        'host_id' => $this->host->id,
        'name' => 'addr-vm-01',
        'incus_project' => 'user-'.$this->tenant->id,
        'instance_type' => 'virtual-machine',
        'status' => Running::class,
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 20,
        'os_image' => 'ubuntu/24.04',
        'ipv4_address' => '192.168.10.3',
        'mac_address' => '02:54:00:00:00:03',
        'network_status' => NetworkStatus::Assigned,
    ]);

    $this->allocated = IpAllocation::create([
        'pool_id' => $this->pool->id,
        'virtual_machine_id' => $this->vm->id,
        'user_id' => $this->tenant->id,
        'address' => inet_pton('192.168.10.3'),
        'ip_string' => '192.168.10.3',
        'mac_address' => '02:54:00:00:00:03',
        'status' => 'allocated',
        'allocated_at' => now()->subHour(),
    ]);

    $this->activeCooling = IpAllocation::create([
        'pool_id' => $this->pool->id,
        'virtual_machine_id' => null,
        'user_id' => $this->tenant->id,
        'address' => inet_pton('192.168.10.4'),
        'ip_string' => '192.168.10.4',
        'mac_address' => '02:54:00:00:00:04',
        'status' => 'cooling',
        'allocated_at' => now()->subHours(2),
        'released_at' => now()->subSeconds(60),
    ]);

    $this->expiredCooling = IpAllocation::create([
        'pool_id' => $this->pool->id,
        'virtual_machine_id' => null,
        'user_id' => $this->tenant->id,
        'address' => inet_pton('192.168.10.5'),
        'ip_string' => '192.168.10.5',
        'mac_address' => '02:54:00:00:00:05',
        'status' => 'cooling',
        'allocated_at' => now()->subHours(3),
        'released_at' => now()->subSeconds(360),
    ]);
});

test('returns all addresses in numeric order with states and embedded allocation details', function (): void {
    Sanctum::actingAs($this->admin);

    $response = $this->getJson("/api/v1/admin/ip-pools/{$this->pool->id}/addresses");

    $response->assertOk()
        ->assertJsonStructure([
            'pool' => ['id', 'name', 'cidr', 'utilization'],
            'data' => [
                '*' => ['ip', 'state', 'is_gateway', 'cooldown_remaining_seconds', 'allocation'],
            ],
            'meta' => ['current_page', 'last_page', 'per_page', 'total', 'from', 'to'],
        ])
        ->assertJsonPath('pool.id', $this->pool->id)
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.last_page', 1)
        ->assertJsonPath('meta.per_page', 50)
        ->assertJsonPath('meta.total', 15)
        ->assertJsonPath('meta.from', 1)
        ->assertJsonPath('meta.to', 15);

    /** @var list<array<string, mixed>> $items */
    $items = $response->json('data');
    expect(array_column($items, 'ip'))->toBe([
        '192.168.10.1',
        '192.168.10.2',
        '192.168.10.3',
        '192.168.10.4',
        '192.168.10.5',
        '192.168.10.6',
        '192.168.10.7',
        '192.168.10.8',
        '192.168.10.9',
        '192.168.10.10',
        '192.168.10.11',
        '192.168.10.12',
        '192.168.10.13',
        '192.168.10.14',
        '192.168.10.15',
    ]);

    // Gateway (excluded + is_gateway=true)
    expect($items[0]['state'])->toBe('excluded')
        ->and($items[0]['is_gateway'])->toBeTrue()
        ->and($items[0]['cooldown_remaining_seconds'])->toBe(0)
        ->and($items[0]['allocation'])->toBeNull();

    // Non-gateway excluded
    expect($items[1]['state'])->toBe('excluded')
        ->and($items[1]['is_gateway'])->toBeFalse()
        ->and($items[1]['allocation'])->toBeNull();

    // Allocated with VM and user
    expect($items[2]['state'])->toBe('allocated')
        ->and($items[2]['is_gateway'])->toBeFalse()
        ->and($items[2]['cooldown_remaining_seconds'])->toBe(0)
        ->and($items[2]['allocation'])->toMatchArray([
            'id' => $this->allocated->id,
            'mac_address' => '02:54:00:00:00:03',
            'status' => 'allocated',
            'virtual_machine' => [
                'id' => $this->vm->id,
                'name' => 'addr-vm-01',
                'status' => 'running',
            ],
            'user' => [
                'id' => $this->tenant->id,
                'name' => 'Address Tenant',
                'email' => 'pool-addr-tenant@test.local',
            ],
        ]);

    // Active cooling
    expect($items[3]['state'])->toBe('cooling')
        ->and($items[3]['is_gateway'])->toBeFalse()
        ->and($items[3]['cooldown_remaining_seconds'])->toBeGreaterThan(0)
        ->and($items[3]['cooldown_remaining_seconds'])->toBeLessThanOrEqual(241)
        ->and($items[3]['allocation']['id'])->toBe($this->activeCooling->id)
        ->and($items[3]['allocation']['status'])->toBe('cooling')
        ->and($items[3]['allocation']['virtual_machine'])->toBeNull()
        ->and($items[3]['allocation']['user']['id'])->toBe($this->tenant->id);

    // Expired cooling treated as free
    expect($items[4]['ip'])->toBe('192.168.10.5')
        ->and($items[4]['state'])->toBe('free')
        ->and($items[4]['is_gateway'])->toBeFalse()
        ->and($items[4]['cooldown_remaining_seconds'])->toBe(0)
        ->and($items[4]['allocation'])->toBeNull();

    // Plain free IP
    expect($items[5]['state'])->toBe('free')
        ->and($items[5]['allocation'])->toBeNull();

    // Last excluded IP
    expect($items[14]['state'])->toBe('excluded')
        ->and($items[14]['is_gateway'])->toBeFalse();
});

test('filters addresses by each state and matches free count with pool utilization', function (): void {
    Sanctum::actingAs($this->admin);

    // 15 total raw - 3 excluded (.1, .2, .15) = 12 usable; minus 1 allocated (.3) and 1 active cooling (.4) = 10 free (including expired cooling .5)
    $freeResponse = $this->getJson("/api/v1/admin/ip-pools/{$this->pool->id}/addresses?state=free");
    $freeResponse->assertOk()
        ->assertJsonPath('meta.total', 10)
        ->assertJsonPath('pool.utilization.free', 10);

    $freeItems = $freeResponse->json('data');
    expect($freeResponse->json('meta.total'))->toBe($freeResponse->json('pool.utilization.free'))
        ->and(array_unique(array_column($freeItems, 'state')))->toBe(['free'])
        ->and(array_column($freeItems, 'ip'))->toContain('192.168.10.5');

    $allocatedResponse = $this->getJson("/api/v1/admin/ip-pools/{$this->pool->id}/addresses?state=allocated");
    $allocatedResponse->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.ip', '192.168.10.3')
        ->assertJsonPath('data.0.state', 'allocated');

    $coolingResponse = $this->getJson("/api/v1/admin/ip-pools/{$this->pool->id}/addresses?state=cooling");
    $coolingResponse->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.ip', '192.168.10.4')
        ->assertJsonPath('data.0.state', 'cooling');

    $excludedResponse = $this->getJson("/api/v1/admin/ip-pools/{$this->pool->id}/addresses?state=excluded");
    $excludedResponse->assertOk()
        ->assertJsonPath('meta.total', 3);

    $excludedItems = $excludedResponse->json('data');
    expect(array_column($excludedItems, 'ip'))->toBe([
        '192.168.10.1',
        '192.168.10.2',
        '192.168.10.15',
    ])
        ->and($excludedItems[0]['is_gateway'])->toBeTrue()
        ->and($excludedItems[1]['is_gateway'])->toBeFalse()
        ->and($excludedItems[2]['is_gateway'])->toBeFalse();
});

test('filters addresses by ipv4 prefix search', function (): void {
    Sanctum::actingAs($this->admin);

    $response = $this->getJson("/api/v1/admin/ip-pools/{$this->pool->id}/addresses?search=192.168.10.1");
    $response->assertOk()
        ->assertJsonPath('meta.total', 7);

    expect(array_column($response->json('data'), 'ip'))->toBe([
        '192.168.10.1',
        '192.168.10.10',
        '192.168.10.11',
        '192.168.10.12',
        '192.168.10.13',
        '192.168.10.14',
        '192.168.10.15',
    ]);

    // Combined with state=free (excludes .1 gateway and .15 excluded)
    $freePrefixResponse = $this->getJson("/api/v1/admin/ip-pools/{$this->pool->id}/addresses?state=free&search=192.168.10.1");
    $freePrefixResponse->assertOk()
        ->assertJsonPath('meta.total', 5);

    expect(array_column($freePrefixResponse->json('data'), 'ip'))->toBe([
        '192.168.10.10',
        '192.168.10.11',
        '192.168.10.12',
        '192.168.10.13',
        '192.168.10.14',
    ]);
});

test('paginates a /16 pool for state=all and state=free quickly with exact totals and items', function (): void {
    Sanctum::actingAs($this->admin);

    $largePool = IpPool::create([
        'host_id' => $this->host->id,
        'name' => 'Large Slash 16 Pool',
        'cidr' => '10.10.0.0/16',
        'first_usable' => '10.10.0.1',
        'last_usable' => '10.10.255.254',
        'gateway' => '10.10.0.1',
        'excluded_ips' => ['10.10.0.1', '10.10.0.3'],
        'network_mode' => 'routed',
        'parent_interface' => 'eth0',
        'cooldown_seconds' => 300,
        'is_public' => false,
        'active' => true,
    ]);

    IpAllocation::create([
        'pool_id' => $largePool->id,
        'virtual_machine_id' => null,
        'user_id' => $this->tenant->id,
        'address' => inet_pton('10.10.0.2'),
        'ip_string' => '10.10.0.2',
        'mac_address' => '02:54:10:10:00:02',
        'status' => 'allocated',
        'allocated_at' => now(),
    ]);

    IpAllocation::create([
        'pool_id' => $largePool->id,
        'virtual_machine_id' => null,
        'user_id' => $this->tenant->id,
        'address' => inet_pton('10.10.0.4'),
        'ip_string' => '10.10.0.4',
        'mac_address' => '02:54:10:10:00:04',
        'status' => 'cooling',
        'allocated_at' => now()->subMinute(),
        'released_at' => now()->subSeconds(30),
    ]);

    // Verify service execution speed (< 50ms) and exact results for state=all and state=free
    $service = app(IpManagementService::class);

    $startAll = hrtime(true);
    $allPage1 = $service->paginatePoolAddresses($largePool, 'all', null, 1, 50);
    $allPage2 = $service->paginatePoolAddresses($largePool, 'all', null, 2, 50);
    $elapsedAllMs = (hrtime(true) - $startAll) / 1_000_000;

    expect($elapsedAllMs)->toBeLessThan(50.0)
        ->and($allPage1['meta']['total'])->toBe(65534)
        ->and($allPage1['meta']['last_page'])->toBe(1311)
        ->and($allPage1['data'][0]['ip'])->toBe('10.10.0.1')
        ->and($allPage1['data'][0]['state'])->toBe('excluded')
        ->and($allPage1['data'][1]['ip'])->toBe('10.10.0.2')
        ->and($allPage1['data'][1]['state'])->toBe('allocated')
        ->and($allPage1['data'][2]['ip'])->toBe('10.10.0.3')
        ->and($allPage1['data'][2]['state'])->toBe('excluded')
        ->and($allPage1['data'][3]['ip'])->toBe('10.10.0.4')
        ->and($allPage1['data'][3]['state'])->toBe('cooling')
        ->and($allPage1['data'][4]['ip'])->toBe('10.10.0.5')
        ->and($allPage1['data'][4]['state'])->toBe('free')
        ->and($allPage2['data'][0]['ip'])->toBe('10.10.0.51')
        ->and($allPage2['meta']['from'])->toBe(51)
        ->and($allPage2['meta']['to'])->toBe(100);

    $startFree = hrtime(true);
    $freePage1 = $service->paginatePoolAddresses($largePool, 'free', null, 1, 50);
    $freePage2 = $service->paginatePoolAddresses($largePool, 'free', null, 2, 50);
    $elapsedFreeMs = (hrtime(true) - $startFree) / 1_000_000;

    // 65534 - 4 non-free (.1, .2, .3, .4) = 65530 free
    expect($elapsedFreeMs)->toBeLessThan(50.0)
        ->and($freePage1['meta']['total'])->toBe(65530)
        ->and($freePage1['data'][0]['ip'])->toBe('10.10.0.5')
        ->and($freePage1['data'][49]['ip'])->toBe('10.10.0.54')
        ->and($freePage2['data'][0]['ip'])->toBe('10.10.0.55')
        ->and($freePage2['meta']['from'])->toBe(51)
        ->and($freePage2['meta']['to'])->toBe(100);

    // Also verify via HTTP endpoint
    $this->getJson("/api/v1/admin/ip-pools/{$largePool->id}/addresses?state=all&per_page=50&page=2")
        ->assertOk()
        ->assertJsonPath('meta.total', 65534)
        ->assertJsonPath('data.0.ip', '10.10.0.51');

    $this->getJson("/api/v1/admin/ip-pools/{$largePool->id}/addresses?state=free&per_page=50&page=2")
        ->assertOk()
        ->assertJsonPath('meta.total', 65530)
        ->assertJsonPath('data.0.ip', '10.10.0.55');
});

test('validates query parameters and enforces admin access', function (): void {
    Sanctum::actingAs($this->admin);

    $this->getJson("/api/v1/admin/ip-pools/{$this->pool->id}/addresses?state=invalid")
        ->assertStatus(422)
        ->assertJsonValidationErrors(['state']);

    $this->getJson("/api/v1/admin/ip-pools/{$this->pool->id}/addresses?per_page=101")
        ->assertStatus(422)
        ->assertJsonValidationErrors(['per_page']);

    $this->getJson("/api/v1/admin/ip-pools/{$this->pool->id}/addresses?page=0")
        ->assertStatus(422)
        ->assertJsonValidationErrors(['page']);

    Sanctum::actingAs($this->tenant);

    $this->getJson("/api/v1/admin/ip-pools/{$this->pool->id}/addresses")
        ->assertStatus(403);
});
