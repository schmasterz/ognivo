<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ExecuteVmActionJob;
use App\Jobs\ProvisionVmJob;
use App\Models\Host;
use App\Models\IpPool;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\Hypervisor\Contracts\HypervisorDriverInterface;
use App\Services\Hypervisor\HypervisorManager;
use App\Services\VmLifecycleService;
use App\States\Vm\Deleted;
use App\States\Vm\Failed;
use App\States\Vm\Provisioning;
use App\States\Vm\Running;
use App\States\Vm\Stopped;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class QueuedVmOperationsCorrectnessTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private User $admin;

    private Host $host;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $this->customer = User::factory()->create(['status' => 'active']);
        $this->customer->assignRole('customer');

        $this->admin = User::factory()->create(['status' => 'active']);
        $this->admin->assignRole('admin');

        $this->host = Host::create([
            'name' => 'test-cluster-node',
            'endpoint_url' => 'https://127.0.0.1:8443',
            'driver' => 'fake',
            'is_active' => true,
        ]);

        IpPool::create([
            'name' => 'Default Pool',
            'subnet' => '10.0.0.0/24',
            'host_id' => $this->host->id,
            'is_public' => true,
        ]);
    }

    protected function tearDown(): void
    {
        HypervisorManager::resetFake();
        parent::tearDown();
    }

    public function test_start_on_running_vm_returns_409_without_dispatching_job(): void
    {
        Queue::fake();

        /** @var VirtualMachine $vm */
        $vm = VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $this->host->id,
            'name' => 'running-test-vm',
            'incus_project' => 'tenant-cust',
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'images:alpine/3.20',
        ]);

        $response = $this->actingAs($this->customer)
            ->postJson("/api/v1/vms/{$vm->id}/action", [
                'action' => 'start',
            ]);

        $response->assertStatus(409);
        $response->assertJsonStructure(['message']);
        Queue::assertNotPushed(ExecuteVmActionJob::class);
    }

    public function test_stop_on_stopped_vm_returns_409_without_dispatching_job(): void
    {
        Queue::fake();

        /** @var VirtualMachine $vm */
        $vm = VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $this->host->id,
            'name' => 'stopped-test-vm',
            'incus_project' => 'tenant-cust',
            'instance_type' => 'virtual-machine',
            'status' => Stopped::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'images:alpine/3.20',
        ]);

        $response = $this->actingAs($this->customer)
            ->postJson("/api/v1/vms/{$vm->id}/action", [
                'action' => 'stop',
            ]);

        $response->assertStatus(409);
        Queue::assertNotPushed(ExecuteVmActionJob::class);
    }

    public function test_customer_sending_force_true_on_delete_gets_force_ignored(): void
    {
        Queue::fake();

        /** @var VirtualMachine $vm */
        $vm = VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $this->host->id,
            'name' => 'delete-force-vm',
            'incus_project' => 'tenant-cust',
            'instance_type' => 'virtual-machine',
            'status' => Stopped::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'images:alpine/3.20',
        ]);

        $response = $this->actingAs($this->customer)
            ->postJson("/api/v1/vms/{$vm->id}/action", [
                'action' => 'delete',
                'force' => true,
            ]);

        $response->assertStatus(200);
        Queue::assertPushed(ExecuteVmActionJob::class, static function (ExecuteVmActionJob $job) use ($vm): bool {
            return $job->vm->id === $vm->id && $job->action === 'delete' && $job->force === false;
        });
    }

    public function test_admin_sending_force_true_on_delete_keeps_force_enabled(): void
    {
        Queue::fake();

        /** @var VirtualMachine $vm */
        $vm = VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $this->host->id,
            'name' => 'admin-delete-force-vm',
            'incus_project' => 'tenant-cust',
            'instance_type' => 'virtual-machine',
            'status' => Stopped::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'images:alpine/3.20',
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/vms/{$vm->id}/action", [
                'action' => 'delete',
                'force' => true,
            ]);

        $response->assertStatus(200);
        Queue::assertPushed(ExecuteVmActionJob::class, static function (ExecuteVmActionJob $job) use ($vm): bool {
            return $job->vm->id === $vm->id && $job->action === 'delete' && $job->force === true;
        });
    }

    public function test_driver_mock_returning_false_on_provision_leads_to_failed_state_and_no_running(): void
    {
        /** @var VirtualMachine $vm */
        $vm = VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $this->host->id,
            'name' => 'fail-prov-vm',
            'incus_project' => 'tenant-cust',
            'instance_type' => 'virtual-machine',
            'status' => Provisioning::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'images:alpine/3.20',
        ]);

        $driver = Mockery::mock(HypervisorDriverInterface::class);
        $driver->shouldReceive('getInstanceStatus')->andReturn('Unknown');
        $driver->shouldReceive('createInstance')->once()->with($vm)->andReturn(false);
        $driver->shouldReceive('deleteInstance')->atMost()->once()->with($vm)->andReturn(true);

        HypervisorManager::fake($driver);
        $manager = new HypervisorManager;

        $job = new ProvisionVmJob($vm);
        $job->handle($manager);

        $fresh = $vm->fresh();
        $this->assertNotNull($fresh);
        $this->assertTrue($fresh->status->equals(Failed::class));
        $this->assertFalse($fresh->status->equals(Running::class));
    }

    public function test_driver_mock_returning_false_on_action_keeps_unchanged_state(): void
    {
        /** @var VirtualMachine $vm */
        $vm = VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $this->host->id,
            'name' => 'fail-start-vm',
            'incus_project' => 'tenant-cust',
            'instance_type' => 'virtual-machine',
            'status' => Stopped::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'images:alpine/3.20',
        ]);

        $driver = Mockery::mock(HypervisorDriverInterface::class);
        $driver->shouldReceive('startInstance')->once()->with($vm)->andReturn(false);

        HypervisorManager::fake($driver);
        $service = app(VmLifecycleService::class);
        $job = new ExecuteVmActionJob($vm, 'start');

        try {
            $job->handle($service);
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Hypervisor failed to start VM', $e->getMessage());
        }

        $fresh = $vm->fresh();
        $this->assertNotNull($fresh);
        $this->assertTrue($fresh->status->equals(Stopped::class));
        $this->assertFalse($fresh->status->equals(Running::class));
    }

    public function test_driver_mock_returning_false_on_delete_never_marks_deleted_even_with_force(): void
    {
        /** @var VirtualMachine $vm */
        $vm = VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $this->host->id,
            'name' => 'fail-delete-vm',
            'incus_project' => 'tenant-cust',
            'instance_type' => 'virtual-machine',
            'status' => Stopped::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'images:alpine/3.20',
        ]);

        $driver = Mockery::mock(HypervisorDriverInterface::class);
        $driver->shouldReceive('deleteInstance')->once()->with($vm)->andReturn(false);

        HypervisorManager::fake($driver);
        $service = app(VmLifecycleService::class);

        $job = new ExecuteVmActionJob($vm, 'delete', force: true);

        try {
            $job->handle($service);
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Hypervisor failed to delete VM', $e->getMessage());
        }

        $fresh = $vm->fresh();
        $this->assertNotNull($fresh);
        $this->assertTrue($fresh->status->equals(Failed::class));
        $this->assertFalse($fresh->status->equals(Deleted::class));
    }

    public function test_driver_throwing_404_on_delete_marks_vm_deleted(): void
    {
        /** @var VirtualMachine $vm */
        $vm = VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $this->host->id,
            'name' => 'not-found-delete-vm',
            'incus_project' => 'tenant-cust',
            'instance_type' => 'virtual-machine',
            'status' => Stopped::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'images:alpine/3.20',
        ]);

        $psrResponse = new Response(404, [], '{"error":"not found"}');
        $clientResponse = new ClientResponse($psrResponse);
        $requestException = new RequestException($clientResponse);

        $driver = Mockery::mock(HypervisorDriverInterface::class);
        $driver->shouldReceive('deleteInstance')->once()->with($vm)->andThrow($requestException);

        HypervisorManager::fake($driver);
        $service = app(VmLifecycleService::class);

        $job = new ExecuteVmActionJob($vm, 'delete', force: false);
        $job->handle($service);

        $fresh = $vm->fresh();
        $this->assertNotNull($fresh);
        $this->assertTrue($fresh->status->equals(Deleted::class));
    }

    public function test_jobs_include_without_overlapping_middleware(): void
    {
        /** @var VirtualMachine $vm */
        $vm = VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $this->host->id,
            'name' => 'middleware-vm',
            'incus_project' => 'tenant-cust',
            'instance_type' => 'virtual-machine',
            'status' => Stopped::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'images:alpine/3.20',
        ]);

        $provisionJob = new ProvisionVmJob($vm);
        $actionJob = new ExecuteVmActionJob($vm, 'start');

        $provisionMiddleware = $provisionJob->middleware();
        $this->assertNotEmpty($provisionMiddleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $provisionMiddleware[0]);

        $actionMiddleware = $actionJob->middleware();
        $this->assertNotEmpty($actionMiddleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $actionMiddleware[0]);
    }
}
