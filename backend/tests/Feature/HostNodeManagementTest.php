<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Host;
use App\Models\IpPool;
use App\Models\PanelCertificate;
use App\Models\QuotaGroup;
use App\Models\User;
use App\Models\UserQuota;
use App\Models\VirtualMachine;
use App\Services\PanelCertificateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class HostNodeManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    private Host $frankfurtHost;

    private Host $sofiaHost;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $this->admin = User::factory()->create(['name' => 'Admin Node Manager', 'email' => 'admin-node@test.local']);
        $this->admin->assignRole('admin');

        $this->customer = User::factory()->create(['name' => 'Tenant Dev', 'email' => 'tenant-node@test.local']);
        $this->customer->assignRole('customer');

        UserQuota::create([
            'user_id' => $this->customer->id,
            'max_vms' => 5,
            'max_cpu_cores' => 16,
            'max_ram_mb' => 16384,
            'max_storage_gb' => 200,
            'max_ips' => 5,
        ]);

        $this->frankfurtHost = Host::firstOrCreate(
            ['name' => 'eu-west-1'],
            [
                'region' => 'Europe West (Frankfurt)',
                'endpoint_url' => 'https://eu-west-1.incus.local:8443',
                'driver' => 'fake',
                'is_active' => true,
                'total_cpu_cores' => 64,
                'total_ram_mb' => 262144,
                'total_storage_gb' => 8000,
                'capacity_source' => 'discovered',
                'capacity_synced_at' => now(),
            ]
        );

        Host::firstOrCreate(
            ['name' => 'us-east-1'],
            [
                'region' => 'US East (N. Virginia)',
                'endpoint_url' => 'https://us-east-1.incus.local:8443',
                'driver' => 'fake',
                'is_active' => true,
                'total_cpu_cores' => 64,
                'total_ram_mb' => 262144,
                'total_storage_gb' => 8000,
                'capacity_source' => 'discovered',
                'capacity_synced_at' => now(),
            ]
        );

        $this->sofiaHost = Host::firstOrCreate(
            ['name' => 'bg-sofia-1'],
            [
                'region' => 'Europe East (Sofia)',
                'endpoint_url' => 'https://bg-sofia-1.incus.local:8443',
                'driver' => 'fake',
                'is_active' => true,
                'total_cpu_cores' => 32,
                'total_ram_mb' => 131072,
                'total_storage_gb' => 4000,
                'capacity_source' => 'discovered',
                'capacity_synced_at' => now(),
            ]
        );

        // Ensure active panel certificate exists for mTLS connection tests
        app(PanelCertificateService::class)->generate();
    }

    public function test_customer_can_list_active_hosts_for_provisioning_but_cannot_modify(): void
    {
        $response = $this->actingAs($this->customer)->getJson('/api/hosts');
        $response->assertOk();
        $response->assertJsonCount(3, 'data');
        $response->assertJsonFragment(['name' => 'eu-west-1', 'region' => 'Europe West (Frankfurt)']);

        // Forbidden admin endpoints for customer
        $this->actingAs($this->customer)->getJson('/api/admin/hosts')->assertForbidden();
        $this->actingAs($this->customer)->postJson('/api/admin/hosts', [
            'name' => 'ap-southeast-1',
            'region' => 'Asia Pacific (Singapore)',
            'endpoint_url' => 'https://ap-southeast-1.incus.local:8443',
            'driver' => 'incus',
        ])->assertForbidden();
    }

    public function test_admin_can_crud_host_nodes(): void
    {
        // 1. List
        $listRes = $this->actingAs($this->admin)->getJson('/api/admin/hosts');
        $listRes->assertOk();
        $listRes->assertJsonCount(3, 'data');

        // 2. Create
        $createRes = $this->actingAs($this->admin)->postJson('/api/admin/hosts', [
            'name' => 'ap-southeast-1',
            'region' => 'Asia Pacific (Singapore)',
            'endpoint_url' => 'https://ap-southeast-1.incus.internal:8443',
            'driver' => 'incus',
            'is_active' => true,
            'total_cpu_cores' => 128,
            'total_ram_mb' => 524288,
            'total_storage_gb' => 8000,
        ]);
        $createRes->assertCreated();
        $newNodeId = (int) $createRes->json('host.id');

        $this->assertDatabaseHas('hosts', [
            'id' => $newNodeId,
            'name' => 'ap-southeast-1',
            'region' => 'Asia Pacific (Singapore)',
        ]);

        // 3. Update
        $updateRes = $this->actingAs($this->admin)->putJson("/api/admin/hosts/{$newNodeId}", [
            'name' => 'ap-southeast-2',
            'region' => 'Asia Pacific (Sydney)',
            'endpoint_url' => 'https://ap-southeast-2.incus.internal:8443',
            'driver' => 'fake',
            'is_active' => false,
            'total_cpu_cores' => 64,
            'total_ram_mb' => 262144,
            'total_storage_gb' => 4000,
        ]);
        $updateRes->assertOk();
        $this->assertDatabaseHas('hosts', [
            'id' => $newNodeId,
            'name' => 'ap-southeast-2',
            'region' => 'Asia Pacific (Sydney)',
            'driver' => 'fake',
            'is_active' => false,
        ]);

        // 4. Delete
        $deleteRes = $this->actingAs($this->admin)->deleteJson("/api/admin/hosts/{$newNodeId}");
        $deleteRes->assertOk();
        $this->assertDatabaseMissing('hosts', ['id' => $newNodeId]);
    }

    public function test_admin_cannot_delete_host_with_running_virtual_machines(): void
    {
        VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $this->frankfurtHost->id,
            'name' => 'prod-api-01',
            'incus_project' => 'default',
            'os_image' => 'ubuntu:24.04',
            'cpu_cores' => 2,
            'ram_mb' => 4096,
            'disk_gb' => 40,
            'status' => 'running',
            'ipv4_address' => '10.200.0.10',
        ]);

        $response = $this->actingAs($this->admin)->deleteJson("/api/admin/hosts/{$this->frankfurtHost->id}");
        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'Cannot delete compute node with active virtual machines deployed on it.']);
    }

    public function test_admin_can_delete_host_that_has_ip_pools_and_deleted_virtual_machines(): void
    {
        $pool = IpPool::create([
            'host_id' => $this->frankfurtHost->id,
            'name' => 'Frankfurt Deletion Pool',
            'subnet' => '10.10.0.0/29',
            'gateway' => '10.10.0.1',
            'ip_version' => 4,
            'dns_servers' => ['8.8.8.8'],
            'active' => true,
        ]);

        $vm = VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $this->frankfurtHost->id,
            'name' => 'historic-vm-01',
            'incus_project' => 'default',
            'os_image' => 'ubuntu:24.04',
            'cpu_cores' => 2,
            'ram_mb' => 4096,
            'disk_gb' => 40,
            'status' => 'deleted',
            'ipv4_address' => '10.200.0.10',
        ]);

        $response = $this->actingAs($this->admin)->deleteJson("/api/admin/hosts/{$this->frankfurtHost->id}");
        $response->assertOk();

        $this->assertDatabaseMissing('hosts', ['id' => $this->frankfurtHost->id]);
        $this->assertDatabaseMissing('ip_pools', ['id' => $pool->id]);
        $this->assertDatabaseHas('virtual_machines', [
            'id' => $vm->id,
            'host_id' => null,
            'status' => 'deleted',
        ]);
    }

    public function test_ip_pools_can_be_assigned_to_specific_host_nodes(): void
    {
        $poolRes = $this->actingAs($this->admin)->postJson('/api/admin/ip-pools', [
            'name' => 'Frankfurt Public Subnet',
            'subnet' => '195.20.10.0/29',
            'gateway' => '195.20.10.1',
            'ip_version' => 4,
            'dns_servers' => ['8.8.8.8', '1.1.1.1'],
            'host_id' => $this->frankfurtHost->id,
            'auto_generate' => true,
        ]);
        $poolRes->assertCreated();
        $poolId = (int) $poolRes->json('pool.id');

        $this->assertDatabaseHas('ip_pools', [
            'id' => $poolId,
            'host_id' => $this->frankfurtHost->id,
            'name' => 'Frankfurt Public Subnet',
        ]);

        // Verify list returns host relation
        $listRes = $this->actingAs($this->admin)->getJson('/api/ip-pools');
        $listRes->assertOk();
        $listRes->assertJsonFragment([
            'name' => 'Frankfurt Public Subnet',
            'host_id' => $this->frankfurtHost->id,
        ]);
    }

    public function test_vm_provisioning_allocates_ip_from_selected_host_pool(): void
    {
        // Pool 1 for Frankfurt: 10.10.0.0/29
        $frankfurtPool = IpPool::create([
            'host_id' => $this->frankfurtHost->id,
            'name' => 'Frankfurt Pool',
            'subnet' => '10.10.0.0/29',
            'gateway' => '10.10.0.1',
            'ip_version' => 4,
            'dns_servers' => ['8.8.8.8'],
            'is_active' => true,
        ]);

        // Pool 2 for Sofia: 10.20.0.0/29
        $sofiaPool = IpPool::create([
            'host_id' => $this->sofiaHost->id,
            'name' => 'Sofia Pool',
            'subnet' => '10.20.0.0/29',
            'gateway' => '10.20.0.1',
            'ip_version' => 4,
            'dns_servers' => ['1.1.1.1'],
            'is_active' => true,
        ]);

        // Provision VM on Sofia host
        $res = $this->actingAs($this->customer)->postJson('/api/vms', [
            'name' => 'sofia-database',
            'os_image' => 'ubuntu:24.04',
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'host_id' => $this->sofiaHost->id,
        ]);

        $res->assertStatus(202);
        $vm = $res->json('virtual_machine');
        $this->assertIsArray($vm);
        $this->assertSame($this->sofiaHost->id, $vm['host_id']);
        $this->assertSame('10.20.0.2', $vm['ipv4_address']);
    }

    public function test_user_quota_is_locked_when_assigned_to_group_and_unlocked_when_custom(): void
    {
        $group = QuotaGroup::create([
            'name' => 'Pro Tier',
            'description' => 'Professional plan',
            'max_vms' => 10,
            'max_cpu_cores' => 32,
            'max_ram_mb' => 65536,
            'max_storage_gb' => 500,
            'max_ips' => 8,
            'is_default' => false,
        ]);

        // 1. Assign group to customer with attempted custom overrides (which must be ignored because group rules apply)
        $updateRes = $this->actingAs($this->admin)->putJson("/api/admin/users/{$this->customer->id}", [
            'name' => 'Tenant Dev',
            'email' => 'tenant-node@test.local',
            'role' => 'customer',
            'quota_group_id' => $group->id,
            'quota' => [
                'max_vms' => 999, // Should be ignored because quota_group_id is locked
            ],
        ]);
        $updateRes->assertOk();

        $this->customer->refresh();
        $this->assertSame($group->id, $this->customer->quota_group_id);
        $this->assertNotNull($this->customer->quota);
        $this->assertSame(10, $this->customer->quota->max_vms); // strictly locked to group

        // 2. Switch to Custom (quota_group_id: null) allowing manual custom editing
        $customRes = $this->actingAs($this->admin)->putJson("/api/admin/users/{$this->customer->id}", [
            'name' => 'Tenant Dev',
            'email' => 'tenant-node@test.local',
            'role' => 'customer',
            'quota_group_id' => null,
            'quota' => [
                'max_vms' => 42,
                'max_cpu_cores' => 84,
                'max_ram_mb' => 100000,
                'max_storage_gb' => 1000,
                'max_ips' => -1, // infinite
            ],
        ]);
        $customRes->assertOk();

        $this->customer->refresh();
        $this->assertNull($this->customer->quota_group_id);
        $this->assertNotNull($this->customer->quota);
        $this->assertSame(42, $this->customer->quota->max_vms);
        $this->assertSame(-1, $this->customer->quota->max_ips);
    }

    public function test_customer_and_admin_can_filter_virtual_machines_by_host_id(): void
    {
        // Create VMs on different hosts
        $frankfurtVm = VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $this->frankfurtHost->id,
            'name' => 'vm-frankfurt-01',
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'status' => 'running',
            'os_image' => 'images:ubuntu/24.04',
        ]);

        $sofiaVm = VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $this->sofiaHost->id,
            'name' => 'vm-sofia-01',
            'incus_project' => 'default',
            'instance_type' => 'container',
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'status' => 'running',
            'os_image' => 'images:alpine/3.19',
        ]);

        // Customer filters by Frankfurt
        $custRes = $this->actingAs($this->customer)->getJson("/api/vms?host_id={$this->frankfurtHost->id}");
        $custRes->assertOk();
        $custRes->assertJsonFragment(['name' => 'vm-frankfurt-01']);
        $custRes->assertJsonMissing(['name' => 'vm-sofia-01']);

        // Customer filters by Sofia
        $custResSofia = $this->actingAs($this->customer)->getJson("/api/vms?host_id={$this->sofiaHost->id}");
        $custResSofia->assertOk();
        $custResSofia->assertJsonFragment(['name' => 'vm-sofia-01']);
        $custResSofia->assertJsonMissing(['name' => 'vm-frankfurt-01']);

        // Admin filters by Sofia
        $adminRes = $this->actingAs($this->admin)->getJson("/api/vms?host_id={$this->sofiaHost->id}");
        $adminRes->assertOk();
        $adminRes->assertJsonFragment(['name' => 'vm-sofia-01']);
        $adminRes->assertJsonMissing(['name' => 'vm-frankfurt-01']);
    }

    public function test_admin_can_create_compute_node_with_lxd_driver(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/admin/hosts', [
            'name' => 'lxd-cluster-01',
            'region' => 'Europe West (Paris)',
            'endpoint_url' => 'https://lxd-01.internal:8443',
            'driver' => 'lxd',
            'is_active' => true,
            'total_cpu_cores' => 64,
            'total_ram_mb' => 262144,
            'total_storage_gb' => 4000,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('host.driver', 'lxd');

        $hostId = (int) $response->json('host.id');
        $host = Host::findOrFail($hostId);
        $this->assertEquals('lxd', $host->driver);
    }

    public function test_admin_can_test_connection_to_fake_compute_node(): void
    {
        // 1. Dynamic test without saved host
        $resDynamic = $this->actingAs($this->admin)->postJson('/api/admin/hosts/verify-connection', [
            'endpoint_url' => 'https://fake-node.internal:8443',
            'driver' => 'fake',
        ]);

        $resDynamic->assertOk();
        $resDynamic->assertJsonPath('success', true);
        $resDynamic->assertJsonPath('server', 'fake');
        $resDynamic->assertJsonPath('auth', 'trusted');
        $resDynamic->assertJsonPath('is_trusted', true);

        // 2. Test saved fake host
        $resSaved = $this->actingAs($this->admin)->postJson("/api/admin/hosts/{$this->frankfurtHost->id}/verify-connection");
        $resSaved->assertOk();
        $resSaved->assertJsonPath('success', true);
        $resSaved->assertJsonPath('server', 'fake');
    }

    public function test_admin_can_test_connection_to_incus_node_with_mocked_http(): void
    {
        // Mock trusted response
        Http::fake([
            'https://incus-node.internal:8443/1.0' => Http::response([
                'type' => 'sync',
                'status' => 'Success',
                'status_code' => 200,
                'metadata' => [
                    'auth' => 'trusted',
                    'environment' => [
                        'server' => 'incus',
                        'server_version' => '6.0.1',
                    ],
                ],
            ], 200),
        ]);

        $res = $this->actingAs($this->admin)->postJson('/api/admin/hosts/verify-connection', [
            'endpoint_url' => 'https://incus-node.internal:8443',
            'driver' => 'incus',
        ]);

        $res->assertOk();
        $res->assertJsonPath('success', true);
        $res->assertJsonPath('server', 'incus');
        $res->assertJsonPath('server_version', '6.0.1');
        $res->assertJsonPath('auth', 'trusted');
        $res->assertJsonPath('is_trusted', true);
    }

    public function test_admin_connection_test_shows_untrusted_warning(): void
    {
        // Mock untrusted response
        Http::fake([
            'https://lxd-untrusted.internal:8443/1.0' => Http::response([
                'type' => 'sync',
                'status' => 'Success',
                'status_code' => 200,
                'metadata' => [
                    'auth' => 'untrusted',
                    'environment' => [
                        'server' => 'lxd',
                        'server_version' => '5.21.1',
                    ],
                ],
            ], 200),
        ]);

        $res = $this->actingAs($this->admin)->postJson('/api/admin/hosts/verify-connection', [
            'endpoint_url' => 'https://lxd-untrusted.internal:8443',
            'driver' => 'lxd',
        ]);

        $res->assertOk();
        $res->assertJsonPath('success', true);
        $res->assertJsonPath('server', 'lxd');
        $res->assertJsonPath('auth', 'untrusted');
        $res->assertJsonPath('is_trusted', false);
    }

    public function test_admin_connection_test_handles_unreachable_node(): void
    {
        Http::fake([
            'https://unreachable.internal:8443/1.0' => function () {
                throw new ConnectionException('Connection refused');
            },
        ]);

        $res = $this->actingAs($this->admin)->postJson('/api/admin/hosts/verify-connection', [
            'endpoint_url' => 'https://unreachable.internal:8443',
            'driver' => 'incus',
        ]);

        $res->assertOk();
        $res->assertJsonPath('success', false);
        $this->assertStringContainsString('Connection refused', (string) $res->json('message'));
    }

    public function test_admin_can_save_host_with_token_and_test_connection(): void
    {
        // 1. Create compute node with token
        $createRes = $this->actingAs($this->admin)->postJson('/api/admin/hosts', [
            'name' => 'incus-token-node',
            'endpoint_url' => 'https://incus-token.internal:8443',
            'driver' => 'incus',
            'token' => 'secret-trust-token-12345',
        ]);

        $createRes->assertCreated();
        $createRes->assertJsonPath('host.has_token', true);

        $hostId = (int) $createRes->json('host.id');
        $host = Host::findOrFail($hostId);
        $this->assertEquals('secret-trust-token-12345', $host->token);

        // 2. Mock Incus response with token
        Http::fake([
            'https://incus-token.internal:8443/1.0' => Http::response([
                'type' => 'sync',
                'status' => 'Success',
                'status_code' => 200,
                'metadata' => [
                    'auth' => 'trusted',
                    'environment' => [
                        'server' => 'incus',
                        'server_version' => '6.0.1',
                    ],
                ],
            ], 200),
        ]);

        $testRes = $this->actingAs($this->admin)->postJson('/api/admin/hosts/verify-connection', [
            'endpoint_url' => 'https://incus-token.internal:8443',
            'driver' => 'incus',
            'token' => 'secret-trust-token-12345',
            'host_id' => $hostId,
        ]);

        $testRes->assertOk();
        $testRes->assertJsonPath('success', true);
        $testRes->assertJsonPath('is_trusted', true);
        $testRes->assertJsonPath('server', 'incus');
    }

    public function test_admin_can_filter_hosts_by_search_status_active_and_driver(): void
    {
        Host::create([
            'name' => 'ap-northeast-1',
            'region' => 'Asia Pacific (Tokyo)',
            'endpoint_url' => 'https://ap-northeast-1.lxd.local:8443',
            'driver' => 'lxd',
            'is_active' => false,
            'status' => 'unreachable',
            'needs_retrust' => true,
            'server_fingerprint' => 'abcdef1234567890',
            'total_cpu_cores' => 32,
            'total_ram_mb' => 131072,
            'total_storage_gb' => 2000,
        ]);

        // Search by region
        $searchRegionRes = $this->actingAs($this->admin)->getJson('/api/admin/hosts?search=Frankfurt');
        $searchRegionRes->assertOk();
        $searchRegionRes->assertJsonCount(1, 'data');
        $searchRegionRes->assertJsonPath('data.0.name', 'eu-west-1');

        // Search by server_fingerprint
        $searchFpRes = $this->actingAs($this->admin)->getJson('/api/admin/hosts?search=abcdef1234567890');
        $searchFpRes->assertOk();
        $searchFpRes->assertJsonCount(1, 'data');
        $searchFpRes->assertJsonPath('data.0.name', 'ap-northeast-1');

        // Filter by status=unreachable
        $statusUnreachableRes = $this->actingAs($this->admin)->getJson('/api/admin/hosts?status=unreachable');
        $statusUnreachableRes->assertOk();
        $statusUnreachableRes->assertJsonCount(1, 'data');
        $statusUnreachableRes->assertJsonPath('data.0.name', 'ap-northeast-1');

        // Filter by status=needs_retrust
        $statusRetrustRes = $this->actingAs($this->admin)->getJson('/api/admin/hosts?status=needs_retrust');
        $statusRetrustRes->assertOk();
        $statusRetrustRes->assertJsonCount(1, 'data');
        $statusRetrustRes->assertJsonPath('data.0.name', 'ap-northeast-1');

        // Filter by is_active=false and is_active=true
        $inactiveRes = $this->actingAs($this->admin)->getJson('/api/admin/hosts?is_active=false');
        $inactiveRes->assertOk();
        $inactiveRes->assertJsonCount(1, 'data');
        $inactiveRes->assertJsonPath('data.0.name', 'ap-northeast-1');

        $activeRes = $this->actingAs($this->admin)->getJson('/api/admin/hosts?is_active=true');
        $activeRes->assertOk();
        $activeRes->assertJsonCount(3, 'data');
        $activeRes->assertJsonMissing(['name' => 'ap-northeast-1']);

        // Filter by driver=lxd
        $driverRes = $this->actingAs($this->admin)->getJson('/api/admin/hosts?driver=lxd');
        $driverRes->assertOk();
        $driverRes->assertJsonCount(1, 'data');
        $driverRes->assertJsonPath('data.0.name', 'ap-northeast-1');
    }

    public function test_admin_certificate_show_auto_generates_active_certificate_when_none_exists(): void
    {
        PanelCertificate::query()->delete();

        $response = $this->actingAs($this->admin)->getJson('/api/admin/incus/certificate');

        $response->assertOk();
        $response->assertJsonPath('certificate.status', 'active');

        $fingerprint = $response->json('certificate.fingerprint');
        $this->assertIsString($fingerprint);
        $this->assertNotSame('', $fingerprint);
    }
}
