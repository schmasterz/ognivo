<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\IpPoolExhaustedException;
use App\Jobs\ExecuteVmActionJob;
use App\Models\DriftReport;
use App\Models\Host;
use App\Models\IpAllocation;
use App\Models\IpPool;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\Hypervisor\Contracts\HypervisorDriverInterface;
use App\Services\Hypervisor\HypervisorManager;
use App\Services\IpManagementService;
use App\Services\VmLifecycleService;
use App\States\Vm\Deleted;
use App\States\Vm\Failed;
use App\States\Vm\Running;
use App\States\Vm\Stopped;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class VmDeletionAndLifecycleUnificationTest extends TestCase
{
    use RefreshDatabase;

    private User $tenant;

    private User $admin;

    private Host $host;

    private IpPool $pool;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $this->tenant = User::factory()->create(['status' => 'active']);
        $this->tenant->assignRole('customer');

        $this->admin = User::factory()->create(['status' => 'active']);
        $this->admin->assignRole('admin');

        $this->host = Host::create([
            'name' => 'unification-host',
            'endpoint_url' => 'https://127.0.0.1:8443',
            'driver' => 'fake',
            'is_active' => true,
        ]);

        $this->pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Lifecycle Pool',
            'cidr' => '10.50.0.0/24',
            'first_usable' => '10.50.0.10',
            'last_usable' => '10.50.0.10',
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
            'active' => true,
            'is_public' => true,
            'cooldown_seconds' => 300,
        ]);
    }

    protected function tearDown(): void
    {
        HypervisorManager::resetFake();
        parent::tearDown();
    }

    public function test_tenant_delete_of_vm_with_allocation_releases_ip_and_drops_quota(): void
    {
        $vm = VirtualMachine::create([
            'user_id' => $this->tenant->id,
            'host_id' => $this->host->id,
            'name' => 'lifecycle-vm',
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu:24.04',
        ]);

        $ipService = app(IpManagementService::class);
        $allocation = $ipService->allocateIp($this->pool, $vm, $this->tenant);

        $this->assertSame(1, IpAllocation::where('user_id', $this->tenant->id)->where('status', 'allocated')->count());

        $response = $this->actingAs($this->tenant)
            ->postJson("/api/v1/vms/{$vm->id}/action", [
                'action' => 'delete',
            ]);

        $response->assertStatus(200);

        $freshVm = $vm->fresh();
        $this->assertTrue($freshVm->status->equals(Deleted::class));
        $this->assertNull($freshVm->ipv4_address);
        $this->assertSame('unassigned', $freshVm->network_status);

        $freshAlloc = $allocation->fresh();
        $this->assertSame('cooling', $freshAlloc->status);
        $this->assertNotNull($freshAlloc->released_at);
        $this->assertSame($this->tenant->id, $freshAlloc->user_id);
        $this->assertNull($freshAlloc->virtual_machine_id);

        // IP quota usage drops by one
        $this->assertSame(0, IpAllocation::where('user_id', $this->tenant->id)->where('status', 'allocated')->count());

        // Pool cooldown prevents immediate re-allocation
        $anotherVm = VirtualMachine::create([
            'user_id' => $this->tenant->id,
            'host_id' => $this->host->id,
            'name' => 'another-vm',
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu:24.04',
        ]);

        $this->expectException(IpPoolExhaustedException::class);
        $ipService->allocateIp($this->pool, $anotherVm, $this->tenant);
    }

    public function test_driver_404_on_delete_is_treated_as_success(): void
    {
        $vm = VirtualMachine::create([
            'user_id' => $this->tenant->id,
            'host_id' => $this->host->id,
            'name' => 'vm-404-delete',
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu:24.04',
        ]);

        $ipService = app(IpManagementService::class);
        $allocation = $ipService->allocateIp($this->pool, $vm, $this->tenant);

        // Mock driver throwing 404 RequestException
        $psrResponse = new Response(404, [], '{"error":"instance not found"}');
        $clientResponse = new ClientResponse($psrResponse);
        $requestException = new RequestException($clientResponse);

        $driver = Mockery::mock(HypervisorDriverInterface::class);
        $driver->shouldReceive('deleteInstance')->once()->with($vm)->andThrow($requestException);

        HypervisorManager::fake($driver);

        $job = new ExecuteVmActionJob($vm, 'delete', force: false);
        $job->handle(app(VmLifecycleService::class));

        $freshVm = $vm->fresh();
        $this->assertTrue($freshVm->status->equals(Deleted::class));

        $freshAlloc = $allocation->fresh();
        $this->assertSame('cooling', $freshAlloc->status);
        $this->assertNotNull($freshAlloc->released_at);
        $this->assertNull($freshAlloc->virtual_machine_id);
    }

    public function test_driver_500_without_force_fails_job_and_leaves_vm_failed_with_allocation_allocated(): void
    {
        $vm = VirtualMachine::create([
            'user_id' => $this->tenant->id,
            'host_id' => $this->host->id,
            'name' => 'vm-500-delete',
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu:24.04',
        ]);

        $ipService = app(IpManagementService::class);
        $allocation = $ipService->allocateIp($this->pool, $vm, $this->tenant);

        // Mock driver throwing 500 error
        $psrResponse = new Response(500, [], '{"error":"internal hypervisor crash"}');
        $clientResponse = new ClientResponse($psrResponse);
        $requestException = new RequestException($clientResponse);

        $driver = Mockery::mock(HypervisorDriverInterface::class);
        $driver->shouldReceive('deleteInstance')->once()->with($vm)->andThrow($requestException);

        HypervisorManager::fake($driver);

        $job = new ExecuteVmActionJob($vm, 'delete', force: false);

        try {
            $job->handle(app(VmLifecycleService::class));
            $this->fail('Expected RequestException was not thrown.');
        } catch (RequestException $e) {
            $this->assertSame(500, $e->response->status());
        }

        $freshVm = $vm->fresh();
        $this->assertTrue($freshVm->status->equals(Failed::class));
        $this->assertFalse($freshVm->status->equals(Deleted::class));

        // Allocation stays 'allocated' so nothing is leaked or lost silently
        $freshAlloc = $allocation->fresh();
        $this->assertSame('allocated', $freshAlloc->status);
        $this->assertNull($freshAlloc->released_at);
        $this->assertSame($vm->id, $freshAlloc->virtual_machine_id);
        $this->assertSame(1, IpAllocation::where('user_id', $this->tenant->id)->where('status', 'allocated')->count());
    }

    public function test_driver_500_with_force_by_admin_succeeds_releases_ip_and_creates_drift_report(): void
    {
        $vm = VirtualMachine::create([
            'user_id' => $this->tenant->id,
            'host_id' => $this->host->id,
            'name' => 'vm-force-delete',
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu:24.04',
        ]);

        $ipService = app(IpManagementService::class);
        $allocation = $ipService->allocateIp($this->pool, $vm, $this->tenant);

        // Mock driver throwing 500 error
        $psrResponse = new Response(500, [], '{"error":"hypervisor storage offline"}');
        $clientResponse = new ClientResponse($psrResponse);
        $requestException = new RequestException($clientResponse);

        $driver = Mockery::mock(HypervisorDriverInterface::class);
        $driver->shouldReceive('deleteInstance')->once()->with($vm)->andThrow($requestException);

        HypervisorManager::fake($driver);

        $job = new ExecuteVmActionJob($vm, 'delete', force: true);
        $job->handle(app(VmLifecycleService::class));

        $freshVm = $vm->fresh();
        $this->assertTrue($freshVm->status->equals(Deleted::class));

        // Allocation released into cooling
        $freshAlloc = $allocation->fresh();
        $this->assertSame('cooling', $freshAlloc->status);
        $this->assertNotNull($freshAlloc->released_at);
        $this->assertNull($freshAlloc->virtual_machine_id);

        // DriftReport row created for orphan instance
        $drift = DriftReport::where('virtual_machine_id', $vm->id)->first();
        $this->assertNotNull($drift);
        $this->assertSame('orphan_instance', $drift->kind);
        $this->assertSame($this->host->id, $drift->host_id);
        $this->assertSame($vm->name, $drift->instance_name);
        $this->assertSame('deleted', $drift->expected_state);
        $this->assertNull($drift->actual_state);
        $this->assertNull($drift->resolved_at);
        $this->assertTrue($drift->details['forced'] ?? false);
        $this->assertSame('force_delete', $drift->details['detected_by'] ?? null);
    }

    public function test_deleting_vm_in_failed_state_releases_ip(): void
    {
        $vm = VirtualMachine::create([
            'user_id' => $this->tenant->id,
            'host_id' => $this->host->id,
            'name' => 'vm-failed-state-delete',
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => Failed::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu:24.04',
        ]);

        $ipService = app(IpManagementService::class);
        $allocation = $ipService->allocateIp($this->pool, $vm, $this->tenant);

        // Deleting the failed VM
        $response = $this->actingAs($this->tenant)
            ->postJson("/api/v1/vms/{$vm->id}/action", [
                'action' => 'delete',
            ]);

        $response->assertStatus(200);

        $freshVm = $vm->fresh();
        $this->assertTrue($freshVm->status->equals(Deleted::class));

        $freshAlloc = $allocation->fresh();
        $this->assertSame('cooling', $freshAlloc->status);
        $this->assertNotNull($freshAlloc->released_at);
        $this->assertNull($freshAlloc->virtual_machine_id);
        $this->assertSame(0, IpAllocation::where('user_id', $this->tenant->id)->where('status', 'allocated')->count());
    }

    public function test_force_delete_stays_admin_only(): void
    {
        Queue::fake();

        $vm = VirtualMachine::create([
            'user_id' => $this->tenant->id,
            'host_id' => $this->host->id,
            'name' => 'force-auth-test-vm',
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => Stopped::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu:24.04',
        ]);

        // Customer attempts force delete -> force is ignored (set to false)
        $this->actingAs($this->tenant)
            ->postJson("/api/v1/vms/{$vm->id}/action", [
                'action' => 'delete',
                'force' => true,
            ])
            ->assertStatus(200);

        Queue::assertPushed(ExecuteVmActionJob::class, static function (ExecuteVmActionJob $job) use ($vm): bool {
            return $job->vm->id === $vm->id && $job->action === 'delete' && $job->force === false;
        });

        // Admin attempts force delete -> force remains true
        $this->actingAs($this->admin)
            ->postJson("/api/v1/vms/{$vm->id}/action", [
                'action' => 'delete',
                'force' => true,
            ])
            ->assertStatus(200);

        Queue::assertPushed(ExecuteVmActionJob::class, static function (ExecuteVmActionJob $job) use ($vm): bool {
            return $job->vm->id === $vm->id && $job->action === 'delete' && $job->force === true;
        });
    }

    public function test_unified_lifecycle_service_handles_restart(): void
    {
        $vm = VirtualMachine::create([
            'user_id' => $this->tenant->id,
            'host_id' => $this->host->id,
            'name' => 'restart-test-vm',
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu:24.04',
        ]);

        $service = app(VmLifecycleService::class);
        $result = $service->restart($vm);

        $this->assertTrue($result);
        $this->assertTrue($vm->fresh()->status->equals(Running::class));
    }
}
