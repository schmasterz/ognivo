<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Host;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\HostCapacityGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class HostNodeResourceNPlusOneTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    /**
     * @var list<Host>
     */
    private array $hosts = [];

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $this->admin = User::factory()->create(['name' => 'Admin User', 'email' => 'admin@test.local']);
        $this->admin->assignRole('admin');

        for ($i = 1; $i <= 5; $i++) {
            $this->hosts[] = Host::create([
                'name' => "compute-node-0{$i}",
                'region' => "region-0{$i}",
                'endpoint_url' => "https://node0{$i}.incus.local:8443",
                'driver' => 'fake',
                'is_active' => true,
                'total_cpu_cores' => 32,
                'total_ram_mb' => 65536,
                'total_storage_gb' => 1000,
                'capacity_source' => 'discovered',
                'capacity_synced_at' => now(),
            ]);
        }
    }

    public function test_admin_listing_vms_across_multiple_hosts_avoids_n_plus_one_committed_usage_queries(): void
    {
        // Create 1 running VM on each of the 5 hosts
        foreach ($this->hosts as $index => $host) {
            VirtualMachine::create([
                'user_id' => $this->admin->id,
                'host_id' => $host->id,
                'name' => "vm-active-{$index}",
                'incus_project' => 'default',
                'os_image' => 'ubuntu:24.04',
                'cpu_cores' => 4,
                'ram_mb' => 8192,
                'disk_gb' => 80,
                'status' => 'running',
                'ipv4_address' => "10.0.{$index}.10",
            ]);
        }

        // Add failed and deleted VMs on host 0 to verify they are excluded from committed capacity
        VirtualMachine::create([
            'user_id' => $this->admin->id,
            'host_id' => $this->hosts[0]->id,
            'name' => 'vm-failed-0',
            'incus_project' => 'default',
            'os_image' => 'ubuntu:24.04',
            'cpu_cores' => 8,
            'ram_mb' => 16384,
            'disk_gb' => 160,
            'status' => 'failed',
        ]);
        VirtualMachine::create([
            'user_id' => $this->admin->id,
            'host_id' => $this->hosts[0]->id,
            'name' => 'vm-deleted-0',
            'incus_project' => 'default',
            'os_image' => 'ubuntu:24.04',
            'cpu_cores' => 8,
            'ram_mb' => 16384,
            'disk_gb' => 160,
            'status' => 'deleted',
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->actingAs($this->admin)->getJson('/api/vms?per_page=50');

        $response->assertOk();
        $response->assertJsonCount(7, 'data');

        /** @var list<array{query: string, bindings: array<mixed>, time: float}> $queries */
        $queries = DB::getQueryLog();

        // Find queries calculating committed usage on virtual_machines
        $committedUsageQueries = array_filter($queries, function (array $q): bool {
            $sql = strtolower($q['query']);

            return str_contains($sql, 'virtual_machines')
                && str_contains($sql, 'sum(')
                && str_contains($sql, 'status');
        });

        // Exactly 1 batch query instead of 5 separate N+1 queries
        $this->assertCount(1, $committedUsageQueries);

        $batchQuery = reset($committedUsageQueries);
        $batchSql = strtolower($batchQuery['query']);
        $this->assertStringContainsString('group by', $batchSql);
        $this->assertStringContainsString('in (', $batchSql);

        // Verify that the capacity values for host 0 exclude failed/deleted VMs
        /** @var list<array{id: int, host_id: int, host: array{id: int, capacity: array{cpu: array{committed: int}, ram: array{committed: int}, disk: array{committed: int}}}}> $data */
        $data = $response->json('data');
        foreach ($data as $vmItem) {
            if ($vmItem['host_id'] === $this->hosts[0]->id) {
                $this->assertSame(4, $vmItem['host']['capacity']['cpu']['committed']);
                $this->assertSame(8192, $vmItem['host']['capacity']['ram']['committed']);
                $this->assertSame(80, $vmItem['host']['capacity']['disk']['committed']);
                break;
            }
        }
    }

    public function test_admin_listing_hosts_avoids_n_plus_one_committed_usage_queries(): void
    {
        // Create VMs on hosts 0, 1, and 2
        VirtualMachine::create([
            'user_id' => $this->admin->id,
            'host_id' => $this->hosts[0]->id,
            'name' => 'vm-h0-1',
            'incus_project' => 'default',
            'os_image' => 'ubuntu:24.04',
            'cpu_cores' => 2,
            'ram_mb' => 4096,
            'disk_gb' => 40,
            'status' => 'running',
        ]);
        VirtualMachine::create([
            'user_id' => $this->admin->id,
            'host_id' => $this->hosts[0]->id,
            'name' => 'vm-h0-2',
            'incus_project' => 'default',
            'os_image' => 'ubuntu:24.04',
            'cpu_cores' => 2,
            'ram_mb' => 4096,
            'disk_gb' => 40,
            'status' => 'stopped',
        ]);
        VirtualMachine::create([
            'user_id' => $this->admin->id,
            'host_id' => $this->hosts[1]->id,
            'name' => 'vm-h1-1',
            'incus_project' => 'default',
            'os_image' => 'ubuntu:24.04',
            'cpu_cores' => 6,
            'ram_mb' => 12288,
            'disk_gb' => 120,
            'status' => 'running',
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->actingAs($this->admin)->getJson('/api/admin/hosts?per_page=50');

        $response->assertOk();
        $response->assertJsonCount(5, 'data');

        /** @var list<array{query: string, bindings: array<mixed>, time: float}> $queries */
        $queries = DB::getQueryLog();

        $committedUsageQueries = array_filter($queries, function (array $q): bool {
            $sql = strtolower($q['query']);

            return str_contains($sql, 'virtual_machines')
                && str_contains($sql, 'sum(')
                && str_contains($sql, 'status');
        });

        // Exactly 1 batch query instead of 5 separate queries
        $this->assertCount(1, $committedUsageQueries);

        $batchQuery = reset($committedUsageQueries);
        $batchSql = strtolower($batchQuery['query']);
        $this->assertStringContainsString('group by', $batchSql);
        $this->assertStringContainsString('in (', $batchSql);

        /** @var list<array{id: int, capacity: array{cpu: array{committed: int}, ram: array{committed: int}, disk: array{committed: int}}}> $hostsData */
        $hostsData = $response->json('data');

        $hostMap = [];
        foreach ($hostsData as $h) {
            $hostMap[$h['id']] = $h['capacity'];
        }

        // Host 0 has 2+2 = 4 CPU, 4096+4096 = 8192 RAM, 40+40 = 80 Disk
        $this->assertSame(4, $hostMap[$this->hosts[0]->id]['cpu']['committed']);
        $this->assertSame(8192, $hostMap[$this->hosts[0]->id]['ram']['committed']);
        $this->assertSame(80, $hostMap[$this->hosts[0]->id]['disk']['committed']);

        // Host 1 has 6 CPU, 12288 RAM, 120 Disk
        $this->assertSame(6, $hostMap[$this->hosts[1]->id]['cpu']['committed']);
        $this->assertSame(12288, $hostMap[$this->hosts[1]->id]['ram']['committed']);
        $this->assertSame(120, $hostMap[$this->hosts[1]->id]['disk']['committed']);

        // Host 3 (index 2) has 0 committed
        $this->assertSame(0, $hostMap[$this->hosts[2]->id]['cpu']['committed']);
        $this->assertSame(0, $hostMap[$this->hosts[2]->id]['ram']['committed']);
        $this->assertSame(0, $hostMap[$this->hosts[2]->id]['disk']['committed']);
    }

    public function test_host_capacity_guard_get_committed_usage_for_host_ids_batch_method(): void
    {
        $guard = app(HostCapacityGuard::class);

        $this->assertSame([], $guard->getCommittedUsageForHostIds([]));

        VirtualMachine::create([
            'user_id' => $this->admin->id,
            'host_id' => $this->hosts[0]->id,
            'name' => 'vm-guard-1',
            'incus_project' => 'default',
            'os_image' => 'ubuntu:24.04',
            'cpu_cores' => 3,
            'ram_mb' => 6144,
            'disk_gb' => 60,
            'status' => 'running',
        ]);

        $usage = $guard->getCommittedUsageForHostIds([$this->hosts[0]->id, $this->hosts[1]->id]);

        $this->assertArrayHasKey($this->hosts[0]->id, $usage);
        $this->assertArrayHasKey($this->hosts[1]->id, $usage);

        $this->assertSame([
            'cpu' => 3,
            'ram' => 6144,
            'disk' => 60,
        ], $usage[$this->hosts[0]->id]);

        $this->assertSame([
            'cpu' => 0,
            'ram' => 0,
            'disk' => 0,
        ], $usage[$this->hosts[1]->id]);
    }
}
