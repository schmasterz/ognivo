<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\AuditAction;
use App\Enums\IpAllocationStatus;
use App\Enums\NetworkStatus;
use App\Jobs\DeleteUserWorkloadsJob;
use App\Jobs\SuspendUserWorkloadsJob;
use App\Models\AuditLog;
use App\Models\Host;
use App\Models\IpAllocation;
use App\Models\IpPool;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\Hypervisor\Drivers\FakeHypervisorDriver;
use App\Services\Hypervisor\HypervisorManager;
use App\Services\IpManagementService;
use App\Services\UserService;
use App\Services\VmLifecycleService;
use App\States\User\Deleting as UserDeleting;
use App\States\User\DeletionFailed as UserDeletionFailed;
use App\States\User\Suspended as UserSuspended;
use App\States\Vm\Deleted;
use App\States\Vm\Running;
use App\States\Vm\Stopped;
use App\States\Vm\Suspended;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class SafeUserDeletionAndSuspensionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    private Host $host;

    private FakeHypervisorDriver $fakeDriver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        $this->fakeDriver = new FakeHypervisorDriver;
        HypervisorManager::fake($this->fakeDriver);

        $this->admin = User::factory()->create([
            'email' => 'admin-safety@test.local',
            'status' => 'active',
        ]);
        $this->admin->assignRole('admin');

        $this->customer = User::factory()->create([
            'email' => 'customer-safety@test.local',
            'status' => 'active',
            'project_name' => 'proj-customer-safety',
        ]);
        $this->customer->assignRole('customer');

        $this->host = Host::create([
            'name' => 'node-safe-1',
            'region' => 'Sofia',
            'endpoint_url' => 'https://10.10.10.1:8443',
            'driver' => 'fake',
            'is_active' => true,
            'total_cpu_cores' => 32,
            'total_ram_mb' => 65536,
            'total_storage_gb' => 1000,
        ]);
    }

    protected function tearDown(): void
    {
        HypervisorManager::resetFake();
        parent::tearDown();
    }

    public function test_hypervisor_failure_leaves_user_in_visible_deletion_failed_state_without_losing_vm_rows(): void
    {
        // 1. Create VMs and an IP assigned to the customer
        $pool = IpPool::create([
            'name' => 'Safety Pool',
            'subnet' => '10.123.45.0/24',
            'gateway' => '10.123.45.1',
            'host_id' => $this->host->id,
        ]);

        $vm1 = VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $this->host->id,
            'name' => 'safe-vm-1',
            'incus_project' => 'proj-customer-safety',
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'ubuntu:24.04',
            'ipv4_address' => '10.123.45.10',
        ]);

        $vm2 = VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $this->host->id,
            'name' => 'safe-vm-2',
            'incus_project' => 'proj-customer-safety',
            'instance_type' => 'container',
            'status' => Stopped::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'alpine:3.20',
            'ipv4_address' => '10.123.45.11',
        ]);

        $ip = IpAllocation::create([
            'pool_id' => $pool->id,
            'user_id' => $this->customer->id,
            'virtual_machine_id' => $vm1->id,
            'address' => inet_pton('10.123.45.10'),
            'ip_string' => '10.123.45.10',
            'status' => 'allocated',
        ]);

        // 2. Configure fake driver to fail on deleteInstance
        $this->fakeDriver->setFailAction('deleteInstance', true);

        // 3. Admin calls DELETE /api/admin/users/{id}
        $response = $this->actingAs($this->admin)
            ->deleteJson(sprintf('/api/v1/admin/users/%d', $this->customer->id));

        // Returns 202 with status deleting
        $response->assertStatus(202);
        $response->assertJson([
            'status' => 'deleting',
        ]);

        // 4. Assert user row STILL exists and is in "deletion_failed" status
        $this->customer->refresh();
        $this->assertTrue($this->customer->status->equals(UserDeletionFailed::class));
        $this->assertDatabaseHas('users', [
            'id' => $this->customer->id,
            'status' => 'deletion_failed',
        ]);

        // 5. Assert VM rows are NOT lost
        $this->assertDatabaseHas('virtual_machines', ['id' => $vm1->id]);
        $this->assertDatabaseHas('virtual_machines', ['id' => $vm2->id]);
        $this->assertSame(2, $this->customer->virtualMachines()->count());

        // 6. Assert IP address was not freed prematurely
        $ip->refresh();
        $this->assertSame($this->customer->id, $ip->user_id);
        $this->assertSame(IpAllocationStatus::Allocated, $ip->status);

        // 7. Admin user list and show endpoints reflect the visible "deletion_failed" state
        $listRes = $this->actingAs($this->admin)->getJson('/api/v1/admin/users?status=deletion_failed');
        $listRes->assertOk();
        $listRes->assertJsonFragment([
            'id' => $this->customer->id,
            'status' => 'deletion_failed',
        ]);

        $showRes = $this->actingAs($this->admin)->getJson(sprintf('/api/v1/admin/users/%d', $this->customer->id));
        $showRes->assertOk();
        $showRes->assertJsonPath('user.status', 'deletion_failed');
    }

    public function test_hypervisor_project_deletion_failure_leaves_user_in_deletion_failed_state_without_losing_vm_rows(): void
    {
        $vm = VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $this->host->id,
            'name' => 'safe-vm-proj-fail',
            'incus_project' => 'proj-customer-safety',
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu:24.04',
        ]);

        // Allow VM deletion to succeed, but project deletion to fail
        $this->fakeDriver->setFailAction('deleteInstance', false);
        $this->fakeDriver->setFailAction('deleteProject', true);

        $response = $this->actingAs($this->admin)
            ->deleteJson(sprintf('/api/v1/admin/users/%d', $this->customer->id));

        $response->assertStatus(202);

        $this->customer->refresh();
        $this->assertTrue($this->customer->status->equals(UserDeletionFailed::class));
        $this->assertDatabaseHas('users', ['id' => $this->customer->id, 'status' => 'deletion_failed']);
        $this->assertDatabaseHas('virtual_machines', ['id' => $vm->id]);
    }

    public function test_successful_user_deletion_deletes_instances_project_frees_ips_and_removes_db_rows(): void
    {
        $pool = IpPool::create([
            'name' => 'Clean Pool',
            'subnet' => '10.200.0.0/24',
            'gateway' => '10.200.0.1',
            'host_id' => $this->host->id,
        ]);

        $vm = VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $this->host->id,
            'name' => 'clean-vm',
            'incus_project' => 'proj-customer-safety',
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu:24.04',
        ]);

        $ip = IpAllocation::create([
            'pool_id' => $pool->id,
            'user_id' => $this->customer->id,
            'virtual_machine_id' => $vm->id,
            'address' => inet_pton('10.200.0.50'),
            'ip_string' => '10.200.0.50',
            'status' => 'allocated',
        ]);

        $this->fakeDriver->projects[] = 'proj-customer-safety';

        $response = $this->actingAs($this->admin)
            ->deleteJson(sprintf('/api/v1/admin/users/%d', $this->customer->id));

        $response->assertStatus(202);

        // Under sync queue, everything completed
        $this->assertDatabaseMissing('users', ['id' => $this->customer->id]);
        $this->assertDatabaseMissing('virtual_machines', ['id' => $vm->id]);

        // IP address allocation is deleted with user cascade in ranged IPAM
        $this->assertDatabaseMissing('ip_allocations', ['id' => $ip->id]);

        // Incus project removed on hypervisor
        $this->assertNotContains('proj-customer-safety', $this->fakeDriver->projects);
    }

    public function test_user_deletion_marks_user_inactive_and_revokes_tokens(): void
    {
        Queue::fake([DeleteUserWorkloadsJob::class]);

        $token = $this->customer->createToken('active-token')->plainTextToken;
        $this->assertCount(1, $this->customer->tokens);

        $response = $this->actingAs($this->admin)
            ->deleteJson(sprintf('/api/v1/admin/users/%d', $this->customer->id));

        $response->assertStatus(202);
        $response->assertJson([
            'status' => 'deleting',
        ]);

        // User is marked deleting (inactive) and tokens are revoked
        $this->customer->refresh();
        $this->assertTrue($this->customer->status->equals(UserDeleting::class));
        $this->assertCount(0, $this->customer->tokens);

        Queue::assertPushed(DeleteUserWorkloadsJob::class, function ($job) {
            return $job->user->id === $this->customer->id;
        });
    }

    public function test_suspend_user_workloads_job_suspends_vms_one_by_one_and_records_failures(): void
    {
        $vm1 = VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $this->host->id,
            'name' => 'batch-vm-1',
            'incus_project' => 'proj-customer-safety',
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu:24.04',
        ]);

        $vm2 = VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $this->host->id,
            'name' => 'batch-vm-2',
            'incus_project' => 'proj-customer-safety',
            'instance_type' => 'container',
            'status' => Stopped::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'alpine:3.20',
        ]);

        // Custom lifecycle service that fails for vm1 but succeeds for vm2
        $lifecycleService = new class(new HypervisorManager) extends VmLifecycleService
        {
            public int $callCount = 0;

            public array $attemptedVms = [];

            public function suspend(VirtualMachine $vm): bool
            {
                $this->callCount++;
                $this->attemptedVms[] = $vm->id;

                if ($vm->name === 'batch-vm-1') {
                    throw new \RuntimeException('Hypervisor timeout suspending VM #1');
                }

                $vm->status->transitionTo(Suspended::class);
                $vm->save();

                return true;
            }
        };

        $job = new SuspendUserWorkloadsJob($this->customer);
        $job->handle($lifecycleService);

        // Both VMs must have been attempted
        $this->assertSame(2, $lifecycleService->callCount);
        $this->assertContains($vm1->id, $lifecycleService->attemptedVms);
        $this->assertContains($vm2->id, $lifecycleService->attemptedVms);

        // VM2 was suspended successfully despite VM1 failure
        $this->assertTrue($vm2->fresh()->status->equals(Suspended::class));
        $this->assertTrue($vm1->fresh()->status->equals(Running::class));
    }

    public function test_user_service_suspend_user_workloads_dispatches_job(): void
    {
        Queue::fake([SuspendUserWorkloadsJob::class]);

        $userService = app(UserService::class);
        $userService->suspendUserWorkloads($this->customer);

        $this->customer->refresh();
        $this->assertTrue($this->customer->status->equals(UserSuspended::class));

        Queue::assertPushed(SuspendUserWorkloadsJob::class, function ($job) {
            return $job->user->id === $this->customer->id;
        });
    }

    public function test_admin_cannot_transition_deleting_user_back_to_active_or_other_assignable_states(): void
    {
        $this->customer->status->transitionTo(UserDeleting::class);

        foreach (['active', 'suspended', 'inactive', 'pending'] as $targetStatus) {
            $statusRes = $this->actingAs($this->admin)
                ->postJson(sprintf('/api/v1/admin/users/%d/status', $this->customer->id), [
                    'status' => $targetStatus,
                ]);
            $statusRes->assertStatus(422);

            $updateRes = $this->actingAs($this->admin)
                ->putJson(sprintf('/api/v1/admin/users/%d', $this->customer->id), [
                    'name' => $this->customer->name,
                    'email' => $this->customer->email,
                    'role' => 'customer',
                    'status' => $targetStatus,
                ]);
            $updateRes->assertStatus(422);
        }

        $this->customer->refresh();
        $this->assertTrue($this->customer->status->equals(UserDeleting::class));
    }

    public function test_deletion_failed_user_can_retry_deletion_or_move_to_inactive_suspended_but_not_active(): void
    {
        Queue::fake([DeleteUserWorkloadsJob::class]);

        $this->customer->status->transitionTo(UserDeleting::class);
        $this->customer->status->transitionTo(UserDeletionFailed::class);

        $activeRes = $this->actingAs($this->admin)
            ->postJson(sprintf('/api/v1/admin/users/%d/status', $this->customer->id), [
                'status' => 'active',
            ]);
        $activeRes->assertStatus(422);

        $pendingRes = $this->actingAs($this->admin)
            ->postJson(sprintf('/api/v1/admin/users/%d/status', $this->customer->id), [
                'status' => 'pending',
            ]);
        $pendingRes->assertStatus(422);

        // Retrying deletion transitions DeletionFailed -> Deleting and queues DeleteUserWorkloadsJob
        $retryRes = $this->actingAs($this->admin)
            ->deleteJson(sprintf('/api/v1/admin/users/%d', $this->customer->id));
        $retryRes->assertStatus(202);

        $this->customer->refresh();
        $this->assertTrue($this->customer->status->equals(UserDeleting::class));
        Queue::assertPushed(DeleteUserWorkloadsJob::class, 1);

        // Calling delete() again while Deleting is idempotent and does not dispatch a duplicate job
        $idempotentRes = $this->actingAs($this->admin)
            ->deleteJson(sprintf('/api/v1/admin/users/%d', $this->customer->id));
        $idempotentRes->assertStatus(202);
        Queue::assertPushed(DeleteUserWorkloadsJob::class, 1);
    }

    public function test_updating_user_to_suspended_via_admin_update_dispatches_suspend_workloads_job(): void
    {
        Queue::fake([SuspendUserWorkloadsJob::class]);

        $res = $this->actingAs($this->admin)
            ->putJson(sprintf('/api/v1/admin/users/%d', $this->customer->id), [
                'name' => $this->customer->name,
                'email' => $this->customer->email,
                'role' => 'customer',
                'status' => 'suspended',
            ]);

        $res->assertOk();
        $this->customer->refresh();
        $this->assertTrue($this->customer->status->equals(UserSuspended::class));

        Queue::assertPushed(SuspendUserWorkloadsJob::class, function ($job): bool {
            return $job->user->id === $this->customer->id;
        });
    }

    public function test_delete_user_workloads_job_is_idempotent_when_already_deletion_failed(): void
    {
        $this->customer->status->transitionTo(UserDeleting::class);
        $this->customer->status->transitionTo(UserDeletionFailed::class);

        $job = new DeleteUserWorkloadsJob($this->customer);
        $job->failed(new \RuntimeException('Simulated retry exhaustion'));

        $this->customer->refresh();
        $this->assertTrue($this->customer->status->equals(UserDeletionFailed::class));
    }

    public function test_delete_user_workloads_job_delegates_ip_release_to_ip_management_service(): void
    {
        $pool = IpPool::create([
            'name' => 'Delegate Release Pool',
            'subnet' => '10.201.0.0/24',
            'gateway' => '10.201.0.1',
            'host_id' => $this->host->id,
        ]);

        // Pre-deleted VM that still has an allocated IP and Assigned network_status
        $vm = VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $this->host->id,
            'name' => 'pre-deleted-vm-with-ip',
            'incus_project' => 'proj-customer-safety',
            'instance_type' => 'virtual-machine',
            'status' => Deleted::class,
            'network_status' => NetworkStatus::Assigned,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu:24.04',
            'ipv4_address' => '10.201.0.50',
        ]);

        $ip = IpAllocation::create([
            'pool_id' => $pool->id,
            'user_id' => $this->customer->id,
            'virtual_machine_id' => $vm->id,
            'address' => inet_pton('10.201.0.50'),
            'ip_string' => '10.201.0.50',
            'status' => IpAllocationStatus::Allocated,
        ]);

        $observedVmNetworkStatus = null;
        $observedIpv4 = 'not-cleared';
        VirtualMachine::saved(function (VirtualMachine $savedVm) use ($vm, &$observedVmNetworkStatus, &$observedIpv4): void {
            if ($savedVm->id === $vm->id) {
                $observedVmNetworkStatus = $savedVm->network_status;
                $observedIpv4 = $savedVm->ipv4_address;
            }
        });

        $job = new DeleteUserWorkloadsJob($this->customer);
        $job->handle(app(VmLifecycleService::class), app(HypervisorManager::class), app(IpManagementService::class));

        $this->assertSame(NetworkStatus::Unassigned, $observedVmNetworkStatus);
        $this->assertNull($observedIpv4);
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::IpReleased->value,
            'subject_id' => (string) $ip->id,
        ]);
        $this->assertSame(1, AuditLog::where('action', AuditAction::IpReleased->value)->where('subject_id', (string) $ip->id)->count());
    }
}
