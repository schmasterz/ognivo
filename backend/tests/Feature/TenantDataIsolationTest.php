<?php

declare(strict_types=1);

use App\Models\Host;
use App\Models\IpAllocation;
use App\Models\IpPool;
use App\Models\User;
use App\Models\UserQuota;
use App\Models\VirtualMachine;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

    $this->admin = User::factory()->create(['name' => 'Admin Sec', 'email' => 'admin-sec@test.local']);
    $this->admin->assignRole('admin');

    $this->customer = User::factory()->create(['name' => 'Customer Sec', 'email' => 'customer-sec@test.local']);
    $this->customer->assignRole('customer');

    UserQuota::create([
        'user_id' => $this->customer->id,
        'max_vms' => 10,
        'max_cpu_cores' => 32,
        'max_ram_mb' => 32768,
        'max_storage_gb' => 500,
        'max_ips' => 10,
    ]);

    // Create a host with full secrets and configuration
    $this->secureHost = Host::create([
        'name' => 'sec-node-01',
        'region' => 'eu-central-1',
        'endpoint_url' => 'https://node01.internal.infra:8443',
        'server_certificate' => "-----BEGIN CERTIFICATE-----\nMIIB...fake...cert\n-----END CERTIFICATE-----",
        'server_fingerprint' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
        'driver' => 'incus',
        'token' => 'secret-trust-token-abc-987',
        'status' => 'ok',
        'needs_retrust' => false,
        'is_active' => true,
        'total_cpu_cores' => 64,
        'total_ram_mb' => 131072,
        'total_storage_gb' => 2000,
        'settings' => [
            'internal_gateway' => '10.250.0.1',
            'api_secret' => 'super-secret-cluster-key',
        ],
    ]);

    // Create IP Pool attached to the secure host
    $this->ipPool = IpPool::create([
        'host_id' => $this->secureHost->id,
        'name' => 'Secure Tenant Pool',
        'subnet' => '198.51.100.0/28',
        'gateway' => '198.51.100.1',
        'dns_servers' => ['1.1.1.1', '8.8.8.8'],
        'ip_version' => 4,
        'is_public' => true,
    ]);

    // Populate pool with IP addresses
    $this->freeIp = IpAllocation::create([
        'pool_id' => $this->ipPool->id,
        'user_id' => $this->customer->id,
        'address' => inet_pton('198.51.100.2'),
        'ip_string' => '198.51.100.2',
        'status' => 'cooling',
    ]);

    $this->allocatedIp = IpAllocation::create([
        'pool_id' => $this->ipPool->id,
        'user_id' => $this->customer->id,
        'address' => inet_pton('198.51.100.3'),
        'ip_string' => '198.51.100.3',
        'status' => 'allocated',
    ]);

    $this->vm = VirtualMachine::create([
        'user_id' => $this->customer->id,
        'host_id' => $this->secureHost->id,
        'name' => 'customer-vm-01',
        'incus_project' => 'proj-sec-01',
        'instance_type' => 'virtual-machine',
        'status' => 'running',
        'cpu_cores' => 2,
        'ram_mb' => 4096,
        'disk_gb' => 40,
        'os_image' => 'debian:12',
        'ipv4_address' => '198.51.100.4',
    ]);

    $this->vmIp = IpAllocation::create([
        'pool_id' => $this->ipPool->id,
        'user_id' => $this->customer->id,
        'virtual_machine_id' => $this->vm->id,
        'address' => inet_pton('198.51.100.4'),
        'ip_string' => '198.51.100.4',
        'status' => 'allocated',
    ]);

    $this->reservedIp = IpAllocation::create([
        'pool_id' => $this->ipPool->id,
        'user_id' => $this->customer->id,
        'address' => inet_pton('198.51.100.5'),
        'ip_string' => '198.51.100.5',
        'status' => 'cooling',
    ]);
});

/**
 * Recursively assert that none of the forbidden keys exist in an array structure.
 *
 * @param  list<string>  $forbiddenKeys
 */
function assertNoForbiddenKeysRecursively(mixed $data, array $forbiddenKeys): void
{
    if (! is_array($data)) {
        return;
    }

    foreach ($data as $key => $value) {
        expect(in_array($key, $forbiddenKeys, true))
            ->toBeFalse("Forbidden key [{$key}] was leaked in response payload!");

        if (is_array($value)) {
            assertNoForbiddenKeysRecursively($value, $forbiddenKeys);
        }
    }
}

test('customer requests never leak host secrets or infrastructure keys across endpoints', function (): void {
    $forbiddenKeys = [
        'token',
        'server_certificate',
        'endpoint_url',
        'server_fingerprint',
        'server_public_key_pin',
        'settings',
        'parent_interface',
        'gateway',
        'dns_servers',
        'cidr',
        'network_mode',
    ];

    // 1. GET /api/ip-pools returns only {id, name, host_id, free: boolean}
    $poolsRes = $this->actingAs($this->customer)->getJson('/api/ip-pools');
    $poolsRes->assertOk();
    $poolsData = $poolsRes->json('data');
    expect($poolsData)->not->toBeEmpty();
    assertNoForbiddenKeysRecursively($poolsRes->json(), $forbiddenKeys);

    $poolItem = collect($poolsData)->firstWhere('id', $this->ipPool->id);
    expect($poolItem)->not->toBeNull();
    expect(array_keys($poolItem))->toEqualCanonicalizing(['id', 'name', 'host_id', 'free']);
    expect($poolItem['free'])->toBeTrue();

    // 2. GET /api/hosts
    $hostsRes = $this->actingAs($this->customer)->getJson('/api/hosts');
    $hostsRes->assertOk();
    $hostsData = $hostsRes->json('data');
    expect($hostsData)->not->toBeEmpty();
    assertNoForbiddenKeysRecursively($hostsRes->json(), $forbiddenKeys);

    $hostItem = collect($hostsData)->firstWhere('id', $this->secureHost->id);
    expect($hostItem)->not->toBeNull();
    expect(array_keys($hostItem))->toEqualCanonicalizing([
        'id',
        'name',
        'region',
        'driver',
        'status',
        'is_active',
        'max_instance',
    ]);

    // 3. GET /api/vms
    $vmsRes = $this->actingAs($this->customer)->getJson('/api/vms');
    $vmsRes->assertOk();
    $vmsData = $vmsRes->json('data');
    expect($vmsData)->not->toBeEmpty();
    assertNoForbiddenKeysRecursively($vmsRes->json(), [
        'token',
        'server_certificate',
        'endpoint_url',
        'server_fingerprint',
        'server_public_key_pin',
        'parent_interface',
        'excluded_ips',
    ]);

    $vmItem = collect($vmsData)->firstWhere('id', $this->vm->id);
    expect($vmItem)->not->toBeNull();
    assertNoForbiddenKeysRecursively($vmItem['host'], $forbiddenKeys);
    expect($vmItem['host'])->toMatchArray([
        'id' => $this->secureHost->id,
        'name' => 'sec-node-01',
        'region' => 'eu-central-1',
        'driver' => 'incus',
        'status' => 'ok',
        'is_active' => true,
    ]);

    // 4. GET /api/ips
    $ipsRes = $this->actingAs($this->customer)->getJson('/api/ips');
    $ipsRes->assertOk();
    $ipsData = $ipsRes->json('data');
    expect($ipsData)->not->toBeEmpty();
    assertNoForbiddenKeysRecursively($ipsRes->json(), $forbiddenKeys);

    $userIpItem = collect($ipsData)->firstWhere('id', $this->allocatedIp->id);
    expect($userIpItem)->not->toBeNull();
    expect($userIpItem)->toHaveKeys(['id', 'address', 'status', 'virtual_machine']);
    expect($userIpItem)->not->toHaveKey('pool');
    expect($userIpItem)->not->toHaveKey('ip_pool');
});

test('admin can retrieve endpoint_url and host details from admin and tenant endpoints', function (): void {
    // 1. GET /api/admin/hosts returns endpoint_url
    $adminHostsRes = $this->actingAs($this->admin)->getJson('/api/admin/hosts');
    $adminHostsRes->assertOk();
    $adminHostsData = $adminHostsRes->json('data');
    $adminHostItem = collect($adminHostsData)->firstWhere('id', $this->secureHost->id);

    expect($adminHostItem)->not->toBeNull();
    expect($adminHostItem)->toHaveKey('endpoint_url');
    expect($adminHostItem['endpoint_url'])->toBe('https://node01.internal.infra:8443');
    expect($adminHostItem)->toHaveKey('server_fingerprint');
    expect($adminHostItem)->toHaveKey('settings');

    // 2. GET /api/hosts returns HostNodeResource for admin
    $hostsRes = $this->actingAs($this->admin)->getJson('/api/hosts');
    $hostsRes->assertOk();
    $hostItem = collect($hostsRes->json('data'))->firstWhere('id', $this->secureHost->id);

    expect($hostItem)->not->toBeNull();
    expect($hostItem)->toHaveKey('endpoint_url');
    expect($hostItem['endpoint_url'])->toBe('https://node01.internal.infra:8443');

    // 3. GET /api/ip-pools returns HostNodeResource for admin
    $poolsRes = $this->actingAs($this->admin)->getJson('/api/ip-pools');
    $poolsRes->assertOk();
    $poolItem = collect($poolsRes->json('data'))->firstWhere('id', $this->ipPool->id);

    expect($poolItem)->not->toBeNull();
    expect($poolItem['host'])->toHaveKey('endpoint_url');
    expect($poolItem['host']['endpoint_url'])->toBe('https://node01.internal.infra:8443');
    expect($poolItem)->toHaveKeys(['total_ips', 'free_ips', 'allocated_ips', 'reserved_ips']);
});

test('host model hides token and server_certificate from array and json serialization', function (): void {
    $array = $this->secureHost->toArray();

    expect(array_key_exists('token', $array))->toBeFalse();
    expect(array_key_exists('server_certificate', $array))->toBeFalse();

    $json = json_encode($this->secureHost);
    expect($json)->not->toContain('secret-trust-token-abc-987');
    expect($json)->not->toContain('MIIB...fake...cert');
});
