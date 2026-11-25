<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Host;
use App\Models\User;
use App\Models\UserQuota;
use App\Models\VirtualMachine;
use App\States\Vm\Running;
use App\States\Vm\Stopped;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AdminManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customerA;

    private User $customerB;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $this->admin = User::factory()->create(['name' => 'Root Admin', 'email' => 'root@ognivo.local']);
        $this->admin->assignRole('admin');

        $this->customerA = User::factory()->create(['name' => 'Alice Customer', 'email' => 'alice@test.local']);
        $this->customerA->assignRole('customer');

        $this->customerB = User::factory()->create(['name' => 'Bob Customer', 'email' => 'bob@test.local']);
        $this->customerB->assignRole('customer');

        UserQuota::create([
            'user_id' => $this->customerA->id,
            'max_vms' => 5,
            'max_cpu_cores' => 10,
            'max_ram_mb' => 10240,
            'max_storage_gb' => 200,
            'max_ips' => 5,
        ]);

        UserQuota::create([
            'user_id' => $this->customerB->id,
            'max_vms' => 5,
            'max_cpu_cores' => 10,
            'max_ram_mb' => 10240,
            'max_storage_gb' => 200,
            'max_ips' => 5,
        ]);

        Host::create([
            'name' => 'cluster-node-01',
            'endpoint_url' => 'https://127.0.0.1:8443',
            'driver' => 'fake',
            'is_active' => true,
            'total_cpu_cores' => 64,
            'total_ram_mb' => 131072,
            'total_storage_gb' => 5000,
        ]);

        // Virtual machines for Alice
        VirtualMachine::create([
            'user_id' => $this->customerA->id,
            'name' => 'alice-web-app',
            'incus_project' => 'user-'.$this->customerA->id,
            'status' => Running::class,
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'images:ubuntu/24.04',
            'ipv4_address' => '198.51.100.10',
        ]);

        // Virtual machines for Bob
        VirtualMachine::create([
            'user_id' => $this->customerB->id,
            'name' => 'bob-db-server',
            'incus_project' => 'user-'.$this->customerB->id,
            'status' => Stopped::class,
            'cpu_cores' => 4,
            'ram_mb' => 4096,
            'disk_gb' => 50,
            'os_image' => 'images:debian/12',
            'ipv4_address' => '198.51.100.20',
        ]);
    }

    public function test_customer_cannot_access_admin_endpoints(): void
    {
        Sanctum::actingAs($this->customerA);

        $this->getJson('/api/v1/admin/users')->assertStatus(403);
        $this->getJson('/api/v1/admin/stats')->assertStatus(403);
    }

    public function test_admin_can_access_user_list_and_cluster_stats(): void
    {
        Sanctum::actingAs($this->admin);

        $usersRes = $this->getJson('/api/v1/admin/users');
        $usersRes->assertStatus(200)
            ->assertJsonCount(3, 'data'); // admin, alice, bob

        $statsRes = $this->getJson('/api/v1/admin/stats');
        $statsRes->assertStatus(200)
            ->assertJsonPath('overview.total_vms', 2)
            ->assertJsonPath('overview.running_vms', 1)
            ->assertJsonPath('overview.allocated_cpu_cores', 6)
            ->assertJsonPath('overview.allocated_ram_mb', 6144);
    }

    public function test_customer_only_sees_own_workloads(): void
    {
        Sanctum::actingAs($this->customerA);

        $response = $this->getJson('/api/v1/vms');
        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'alice-web-app');
    }

    public function test_admin_sees_all_workloads_across_tenants(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/v1/vms');
        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_admin_can_filter_workloads_by_tenant_user_id(): void
    {
        Sanctum::actingAs($this->admin);

        // Filter Alice's machines
        $aliceRes = $this->getJson('/api/v1/vms?user_id='.$this->customerA->id);
        $aliceRes->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'alice-web-app');

        // Filter Bob's machines
        $bobRes = $this->getJson('/api/v1/vms?user_id='.$this->customerB->id);
        $bobRes->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'bob-db-server');
    }

    public function test_admin_can_filter_workloads_by_status_and_search(): void
    {
        Sanctum::actingAs($this->admin);

        // Status filter: running
        $runningRes = $this->getJson('/api/v1/vms?status=running');
        $runningRes->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'alice-web-app');

        // Search by keyword
        $searchRes = $this->getJson('/api/v1/vms?search=db-server');
        $searchRes->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'bob-db-server');
    }

    public function test_admin_is_strictly_forbidden_from_accessing_workload_console(): void
    {
        Sanctum::actingAs($this->admin);

        $vm = VirtualMachine::where('name', 'alice-web-app')->firstOrFail();

        $response = $this->getJson("/api/v1/vms/{$vm->id}/console-log");
        $response->assertStatus(403);
    }
}
