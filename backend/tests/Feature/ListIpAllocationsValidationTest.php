<?php

declare(strict_types=1);

use App\Models\Host;
use App\Models\IpAllocation;
use App\Models\IpPool;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

    $this->admin = User::factory()->create(['email' => 'ip-val-admin@test.local']);
    $this->admin->assignRole('admin');

    $this->tenant = User::factory()->create(['email' => 'ip-val-tenant@test.local']);
    $this->tenant->assignRole('customer');

    $this->otherTenant = User::factory()->create(['email' => 'ip-val-other@test.local']);
    $this->otherTenant->assignRole('customer');

    $this->host1 = Host::create([
        'name' => 'ip-val-host-1',
        'endpoint_url' => 'https://127.0.0.1:8443',
        'driver' => 'fake',
        'is_active' => true,
    ]);

    $this->host2 = Host::create([
        'name' => 'ip-val-host-2',
        'endpoint_url' => 'https://127.0.0.2:8443',
        'driver' => 'fake',
        'is_active' => true,
    ]);

    $this->pool1 = IpPool::create([
        'host_id' => $this->host1->id,
        'name' => 'Val Pool One',
        'cidr' => '203.0.113.0/24',
        'first_usable' => '203.0.113.10',
        'last_usable' => '203.0.113.50',
        'gateway' => '203.0.113.1',
        'network_mode' => 'routed',
        'parent_interface' => 'eth0',
        'cooldown_seconds' => 300,
        'is_public' => true,
        'active' => true,
    ]);

    $this->pool2 = IpPool::create([
        'host_id' => $this->host2->id,
        'name' => 'Val Pool Two',
        'cidr' => '198.51.100.0/24',
        'first_usable' => '198.51.100.10',
        'last_usable' => '198.51.100.50',
        'gateway' => '198.51.100.1',
        'network_mode' => 'routed',
        'parent_interface' => 'eth0',
        'cooldown_seconds' => 300,
        'is_public' => true,
        'active' => true,
    ]);

    $this->tenantAllocated = IpAllocation::create([
        'pool_id' => $this->pool1->id,
        'user_id' => $this->tenant->id,
        'address' => inet_pton('203.0.113.10'),
        'ip_string' => '203.0.113.10',
        'mac_address' => '02:54:00:00:00:10',
        'status' => 'allocated',
        'allocated_at' => now(),
    ]);

    $this->tenantCooling = IpAllocation::create([
        'pool_id' => $this->pool2->id,
        'user_id' => $this->tenant->id,
        'address' => inet_pton('198.51.100.10'),
        'ip_string' => '198.51.100.10',
        'mac_address' => '02:54:00:00:00:11',
        'status' => 'cooling',
        'allocated_at' => now()->subHour(),
        'released_at' => now(),
    ]);

    $this->otherAllocated = IpAllocation::create([
        'pool_id' => $this->pool1->id,
        'user_id' => $this->otherTenant->id,
        'address' => inet_pton('203.0.113.20'),
        'ip_string' => '203.0.113.20',
        'mac_address' => '02:54:00:00:00:20',
        'status' => 'allocated',
        'allocated_at' => now(),
    ]);
});

test('GET /api/ips preserves default pagination and response shape', function (): void {
    Sanctum::actingAs($this->admin);

    $response = $this->getJson('/api/v1/ips');

    $response->assertOk()
        ->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'last_page', 'per_page', 'total', 'from', 'to'],
        ])
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.last_page', 1)
        ->assertJsonPath('meta.per_page', 10)
        ->assertJsonPath('meta.total', 3)
        ->assertJsonMissingPath('current_page')
        ->assertJsonMissingPath('last_page')
        ->assertJsonMissingPath('per_page')
        ->assertJsonMissingPath('total');
});

test('GET /api/ips works with each valid filter', function (): void {
    Sanctum::actingAs($this->admin);

    // user_id
    $this->getJson('/api/v1/ips?user_id='.$this->otherTenant->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->otherAllocated->id);

    // host_id
    $this->getJson('/api/v1/ips?host_id='.$this->host2->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->tenantCooling->id);

    // pool_id
    $this->getJson('/api/v1/ips?pool_id='.$this->pool2->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->tenantCooling->id);

    // status: allocated, cooling
    $this->getJson('/api/v1/ips?status=allocated')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->getJson('/api/v1/ips?status=cooling')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->tenantCooling->id);

    // search (IP prefix and boundary 64-char string)
    $this->getJson('/api/v1/ips?search=203.0.113.20')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->otherAllocated->id);

    $this->getJson('/api/v1/ips?search='.str_repeat('a', 64))
        ->assertOk()
        ->assertJsonCount(0, 'data');

    // per_page and page
    $this->getJson('/api/v1/ips?per_page=1&page=2')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.per_page', 1)
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.total', 3)
        ->assertJsonMissingPath('current_page')
        ->assertJsonMissingPath('last_page')
        ->assertJsonMissingPath('per_page')
        ->assertJsonMissingPath('total');

    $this->getJson('/api/v1/ips?per_page=100')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 100);
});

test('GET /api/ips rejects invalid query parameters with 422', function (string $query, string $errorField): void {
    Sanctum::actingAs($this->admin);

    $this->getJson('/api/v1/ips?'.$query)
        ->assertStatus(422)
        ->assertJsonValidationErrors([$errorField]);
})->with([
    'per_page zero' => ['per_page=0', 'per_page'],
    'per_page over max' => ['per_page=101', 'per_page'],
    'status bogus' => ['status=bogus', 'status'],
    'status released' => ['status=released', 'status'],
    'pool_id non-integer' => ['pool_id=abc', 'pool_id'],
    'pool_id zero' => ['pool_id=0', 'pool_id'],
    'host_id non-integer' => ['host_id=abc', 'host_id'],
    'user_id zero' => ['user_id=0', 'user_id'],
    'page zero' => ['page=0', 'page'],
    'search over 64 chars' => ['search='.str_repeat('a', 65), 'search'],
]);

test('tenant cannot view another user allocations via user_id filter', function (): void {
    Sanctum::actingAs($this->tenant);

    $response = $this->getJson('/api/v1/ips?user_id='.$this->otherTenant->id);

    $response->assertOk();

    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toContain($this->tenantAllocated->id, $this->tenantCooling->id)
        ->not->toContain($this->otherAllocated->id);
});
