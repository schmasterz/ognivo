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
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class QuotaFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private User $admin;

    private Host $hostA;

    private Host $hostB;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $this->admin = User::factory()->create(['name' => 'Admin User', 'email' => 'admin@test.local']);
        $this->admin->assignRole('admin');

        $this->customer = User::factory()->create(['name' => 'Customer User', 'email' => 'customer@test.local']);
        $this->customer->assignRole('customer');

        UserQuota::create([
            'user_id' => $this->customer->id,
            'max_vms' => 10,
            'max_cpu_cores' => 20,
            'max_ram_mb' => 20480,
            'max_storage_gb' => 500,
            'max_ips' => 5,
        ]);

        $this->hostA = Host::create([
            'name' => 'eu-west-node',
            'region' => 'eu-west-1',
            'endpoint_url' => 'https://eu-west.local:8443',
            'driver' => 'fake',
            'is_active' => true,
        ]);

        $this->hostB = Host::create([
            'name' => 'us-east-node',
            'region' => 'us-east-1',
            'endpoint_url' => 'https://us-east.local:8443',
            'driver' => 'fake',
            'is_active' => true,
        ]);

        // VM 1 on Host A: 2 cores, 2048 MB RAM, 20 GB disk, status running
        VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $this->hostA->id,
            'name' => 'vm-prod-web',
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'images:ubuntu/24.04',
        ]);

        // VM 2 on Host B: 4 cores, 4096 MB RAM, 50 GB disk, status stopped
        VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $this->hostB->id,
            'name' => 'vm-dev-db',
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => Stopped::class,
            'cpu_cores' => 4,
            'ram_mb' => 4096,
            'disk_gb' => 50,
            'os_image' => 'images:debian/12',
        ]);
    }

    public function test_quota_metrics_reflect_total_usage_without_filters(): void
    {
        $response = $this->actingAs($this->customer)->getJson('/api/quota');

        $response->assertStatus(200);
        $response->assertJsonPath('usage.vms', 2);
        $response->assertJsonPath('usage.cpu_cores', 6);
        $response->assertJsonPath('usage.ram_mb', 6144);
        $response->assertJsonPath('usage.disk_gb', 70);
    }

    public function test_quota_metrics_filter_by_host_id(): void
    {
        $response = $this->actingAs($this->customer)->getJson('/api/quota?host_id='.$this->hostA->id);

        $response->assertStatus(200);
        $response->assertJsonPath('usage.vms', 1);
        $response->assertJsonPath('usage.cpu_cores', 2);
        $response->assertJsonPath('usage.ram_mb', 2048);
        $response->assertJsonPath('usage.disk_gb', 20);
        $response->assertJsonPath('filters.host_id', $this->hostA->id);
    }

    public function test_quota_metrics_filter_by_status(): void
    {
        $response = $this->actingAs($this->customer)->getJson('/api/quota?status=running');

        $response->assertStatus(200);
        $response->assertJsonPath('usage.vms', 1);
        $response->assertJsonPath('usage.cpu_cores', 2);
        $response->assertJsonPath('filters.status', 'running');
    }

    public function test_quota_metrics_filter_by_search(): void
    {
        $response = $this->actingAs($this->customer)->getJson('/api/quota?search=db');

        $response->assertStatus(200);
        $response->assertJsonPath('usage.vms', 1);
        $response->assertJsonPath('usage.cpu_cores', 4);
        $response->assertJsonPath('usage.ram_mb', 4096);
        $response->assertJsonPath('filters.search', 'db');
    }

    public function test_admin_cluster_stats_filter_by_host_node(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/admin/stats?host_id='.$this->hostA->id);

        $response->assertStatus(200);
        $response->assertJsonPath('overview.total_vms', 1);
        $response->assertJsonPath('overview.running_vms', 1);
        $response->assertJsonPath('overview.allocated_cpu_cores', 2);
        $response->assertJsonPath('overview.allocated_ram_mb', 2048);
        $response->assertJsonPath('overview.allocated_disk_gb', 20);
    }
}
