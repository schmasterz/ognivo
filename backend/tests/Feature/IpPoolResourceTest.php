<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTO\Network\CreateIpPoolDTO;
use App\Http\Resources\IpPoolResource;
use App\Models\Host;
use App\Models\IpPool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class IpPoolResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Host $host;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->admin = User::factory()->create(['email' => 'resource-admin@test.local']);
        $this->admin->assignRole('admin');

        $this->host = Host::create([
            'name' => 'pool-res-host',
            'endpoint_url' => 'https://127.0.0.1:8443',
            'driver' => 'fake',
            'is_active' => true,
        ]);
    }

    public function test_manual_pool_created_without_interface_stores_empty_string_and_returns_null(): void
    {
        Sanctum::actingAs($this->admin);

        $createRes = $this->postJson('/api/v1/admin/ip-pools', [
            'name' => 'Manual Null Interface Pool',
            'host_id' => $this->host->id,
            'cidr' => '198.51.110.0/24',
            'gateway' => '198.51.110.1',
            'network_mode' => 'manual',
        ]);

        $createRes->assertCreated();
        $createRes->assertJsonPath('pool.parent_interface', null);

        $this->assertDatabaseHas('ip_pools', [
            'name' => 'Manual Null Interface Pool',
            'network_mode' => 'manual',
            'parent_interface' => '',
        ]);

        $listRes = $this->getJson('/api/v1/admin/ip-pools?host_id='.$this->host->id);
        $listRes->assertOk();
        $listRes->assertJsonPath('data.0.parent_interface', null);
    }

    public function test_routed_pool_created_with_interface_returns_stored_interface(): void
    {
        Sanctum::actingAs($this->admin);

        $createRes = $this->postJson('/api/v1/admin/ip-pools', [
            'name' => 'Routed Eno1 Pool',
            'host_id' => $this->host->id,
            'cidr' => '198.51.111.0/24',
            'gateway' => '198.51.111.1',
            'network_mode' => 'routed',
            'parent_interface' => 'eno1',
        ]);

        $createRes->assertCreated();
        $createRes->assertJsonPath('pool.parent_interface', 'eno1');

        $listRes = $this->getJson('/api/v1/admin/ip-pools?host_id='.$this->host->id);
        $listRes->assertOk();
        $listRes->assertJsonPath('data.0.parent_interface', 'eno1');
    }

    public function test_create_ip_pool_dto_does_not_default_parent_interface_to_eth0(): void
    {
        $dtoOmitted = CreateIpPoolDTO::fromArray([
            'name' => 'Manual Omitted',
            'cidr' => '198.51.112.0/24',
            'host_id' => $this->host->id,
            'network_mode' => 'manual',
        ]);
        $this->assertSame('', $dtoOmitted->parentInterface);

        $dtoNull = CreateIpPoolDTO::fromArray([
            'name' => 'Manual Null',
            'cidr' => '198.51.113.0/24',
            'host_id' => $this->host->id,
            'network_mode' => 'manual',
            'parent_interface' => null,
        ]);
        $this->assertSame('', $dtoNull->parentInterface);

        $dtoExplicit = CreateIpPoolDTO::fromArray([
            'name' => 'Routed Explicit',
            'cidr' => '198.51.114.0/24',
            'host_id' => $this->host->id,
            'network_mode' => 'routed',
            'parent_interface' => 'eno1',
        ]);
        $this->assertSame('eno1', $dtoExplicit->parentInterface);
    }

    public function test_ip_pool_resource_maps_empty_parent_interface_to_null(): void
    {
        $poolEmpty = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Resource Empty',
            'cidr' => '10.200.0.0/24',
            'first_usable' => '10.200.0.10',
            'last_usable' => '10.200.0.100',
            'network_mode' => 'manual',
            'parent_interface' => '',
        ]);

        $poolSet = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Resource Eno1',
            'cidr' => '10.201.0.0/24',
            'first_usable' => '10.201.0.10',
            'last_usable' => '10.201.0.100',
            'network_mode' => 'routed',
            'parent_interface' => 'eno1',
        ]);

        $request = Request::create('/api/v1/admin/ip-pools', 'GET');
        $request->setUserResolver(fn (): User => $this->admin);

        $emptyArray = (new IpPoolResource($poolEmpty))->toArray($request);
        $setArray = (new IpPoolResource($poolSet))->toArray($request);

        $this->assertNull($emptyArray['parent_interface']);
        $this->assertSame('eno1', $setArray['parent_interface']);
    }

    public function test_ip_pool_resource_includes_excluded_ips_without_gateway_and_omits_vlan_id(): void
    {
        Sanctum::actingAs($this->admin);

        $createRes = $this->postJson('/api/v1/admin/ip-pools', [
            'name' => 'Excluded IPs Resource Pool',
            'host_id' => $this->host->id,
            'cidr' => '198.51.120.0/24',
            'gateway' => '198.51.120.1',
            'excluded_ips' => ['198.51.120.10', '198.51.120.20'],
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
        ]);

        $createRes->assertCreated();
        $createRes->assertJsonPath('pool.gateway', '198.51.120.1');
        $createRes->assertJsonPath('pool.excluded_ips', ['198.51.120.10', '198.51.120.20']);
        $createRes->assertJsonMissingPath('pool.vlan_id');

        $listRes = $this->getJson('/api/v1/admin/ip-pools?host_id='.$this->host->id);
        $listRes->assertOk();
        $listRes->assertJsonPath('data.0.gateway', '198.51.120.1');
        $listRes->assertJsonPath('data.0.excluded_ips', ['198.51.120.10', '198.51.120.20']);
        $listRes->assertJsonMissingPath('data.0.vlan_id');
    }

    public function test_ip_pool_endpoints_return_only_meta_pagination_without_duplicate_root_keys(): void
    {
        IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Paginated Pool A',
            'cidr' => '10.202.0.0/24',
            'first_usable' => '10.202.0.10',
            'last_usable' => '10.202.0.50',
            'gateway' => '10.202.0.1',
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
            'active' => true,
        ]);

        IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Paginated Pool B',
            'cidr' => '10.203.0.0/24',
            'first_usable' => '10.203.0.10',
            'last_usable' => '10.203.0.50',
            'gateway' => '10.203.0.1',
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
            'active' => true,
        ]);

        Sanctum::actingAs($this->admin);

        foreach (['/api/v1/admin/ip-pools?per_page=1&page=1', '/api/v1/ip-pools?per_page=1&page=1'] as $endpoint) {
            $response = $this->getJson($endpoint);

            $response->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonStructure([
                    'data',
                    'meta' => ['current_page', 'last_page', 'per_page', 'total', 'from', 'to'],
                ])
                ->assertJsonPath('meta.current_page', 1)
                ->assertJsonPath('meta.last_page', 2)
                ->assertJsonPath('meta.per_page', 1)
                ->assertJsonPath('meta.total', 2)
                ->assertJsonMissingPath('current_page')
                ->assertJsonMissingPath('last_page')
                ->assertJsonMissingPath('per_page')
                ->assertJsonMissingPath('total');
        }
    }
}
