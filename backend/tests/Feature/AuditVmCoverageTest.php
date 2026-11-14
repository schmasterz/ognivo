<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\AuditContext;
use App\Models\Host;
use App\Models\IpPool;
use App\Models\User;
use App\Models\UserQuota;
use App\Models\VirtualMachine;
use App\Services\Hypervisor\Drivers\FakeHypervisorDriver;
use App\Services\Hypervisor\HypervisorManager;
use App\Services\VmLifecycleService;
use App\Services\VmProvisioningService;
use App\Services\VmReconciliationService;
use App\States\Vm\Provisioning;
use App\States\Vm\Running;
use App\States\Vm\Stopped;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class AuditVmCoverageTest extends TestCase
{
    use AuditAssertionTrait;
    use RefreshDatabase;

    private User $tenant;

    private User $otherTenant;

    private User $admin;

    private Host $host;

    private IpPool $pool;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        config(['app.allow_fake_driver' => true]);
        HypervisorManager::fake(new FakeHypervisorDriver);

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $this->tenant = User::factory()->create(['status' => 'active']);
        $this->tenant->assignRole('customer');

        $this->otherTenant = User::factory()->create(['status' => 'active']);
        $this->otherTenant->assignRole('customer');

        $this->admin = User::factory()->create(['status' => 'active']);
        $this->admin->assignRole('admin');

        $this->host = Host::create([
            'name' => 'vm-test-host-'.uniqid(),
            'endpoint_url' => 'https://127.0.0.1:8443',
            'driver' => 'fake',
            'status' => 'ok',
            'is_active' => true,
            'total_cpu_cores' => 32,
            'total_ram_mb' => 65536,
            'total_storage_gb' => 1000,
            'capacity_source' => 'discovered',
            'capacity_synced_at' => now(),
        ]);

        $this->pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'VM IP Pool '.uniqid(),
            'cidr' => '10.60.0.0/24',
            'first_usable' => '10.60.0.10',
            'last_usable' => '10.60.0.50',
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
            'is_public' => true,
        ]);

        UserQuota::create([
            'user_id' => $this->tenant->id,
            'max_vms' => 10,
            'max_cpu_cores' => 32,
            'max_ram_mb' => 65536,
            'max_storage_gb' => 1000,
            'max_ips' => 10,
            'max_snapshots' => 5,
        ]);

        app(AuditContext::class)->setRequestId('req-vm-test-1234');
    }

    protected function tearDown(): void
    {
        HypervisorManager::resetFake();
        parent::tearDown();
    }

    public function test_vm_create_requested_audited_without_cloud_init(): void
    {
        $provisioningService = app(VmProvisioningService::class);

        $vm = $provisioningService->provision($this->tenant, [
            'name' => 'audit-test-vm',
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'images:ubuntu/24.04',
            'instance_type' => 'virtual-machine',
            'host_id' => $this->host->id,
            'cloud_init' => [
                'user-data' => '#cloud-config\npassword: secret-password-here',
            ],
        ]);

        $row = $this->assertAuditRow(
            action: 'vm.create.requested',
            outcome: 'success',
            tenantUserId: $this->tenant->id,
            visibility: 'tenant',
            requestId: 'req-vm-test-1234',
        );

        $context = json_decode((string) $row->context, true);
        $this->assertIsArray($context['spec']);
        $this->assertSame('audit-test-vm', $context['spec']['name']);
        $this->assertSame(2, $context['spec']['cpu_cores']);
        $this->assertArrayNotHasKey('cloud_init', $context['spec']);
        $this->assertArrayNotHasKey('user-data', $context['spec']);
        $this->assertStringNotContainsString('secret-password-here', (string) $row->context);
    }

    public function test_vm_action_requested_audited(): void
    {
        $vm = VirtualMachine::create([
            'user_id' => $this->tenant->id,
            'host_id' => $this->host->id,
            'name' => 'running-vm-'.uniqid(),
            'incus_project' => 'user-'.$this->tenant->id,
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'images:ubuntu/24.04',
        ]);

        $lifecycleService = app(VmLifecycleService::class);
        $lifecycleService->handleActionRequest($vm, 'stop', false, $this->tenant);

        $row = $this->assertAuditRow(
            action: 'vm.action.requested',
            outcome: 'success',
            tenantUserId: $this->tenant->id,
            visibility: 'tenant',
            requestId: 'req-vm-test-1234',
        );

        $context = json_decode((string) $row->context, true);
        $this->assertSame('stop', $context['action']);
        $this->assertFalse($context['force']);
    }

    public function test_vm_action_denied_on_policy_and_invalid_transition(): void
    {
        $vm = VirtualMachine::create([
            'user_id' => $this->tenant->id,
            'host_id' => $this->host->id,
            'name' => 'policy-vm-'.uniqid(),
            'incus_project' => 'user-'.$this->tenant->id,
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'images:ubuntu/24.04',
        ]);

        $lifecycleService = app(VmLifecycleService::class);

        // 1. Policy denial: otherTenant tries to stop tenant's VM
        try {
            $lifecycleService->handleActionRequest($vm, 'stop', false, $this->otherTenant);
            $this->fail('Expected 403 HttpException');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $policyRow = $this->assertAuditRow(
            action: 'vm.action.denied',
            outcome: 'denied',
            tenantUserId: $this->tenant->id,
            visibility: 'tenant',
            requestId: 'req-vm-test-1234',
        );
        $policyContext = json_decode((string) $policyRow->context, true);
        $this->assertSame('policy_denial', $policyContext['reason']);
        $this->assertSame('stop', $policyContext['action']);

        // 2. Invalid state transition: start on an already Running VM
        try {
            $lifecycleService->handleActionRequest($vm, 'start', false, $this->tenant);
            $this->fail('Expected 409 HttpException');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $transitionRow = $this->assertAuditRow(
            action: 'vm.action.denied',
            outcome: 'denied',
            tenantUserId: $this->tenant->id,
            visibility: 'tenant',
            requestId: 'req-vm-test-1234',
        );
        $transitionContext = json_decode((string) $transitionRow->context, true);
        $this->assertSame('invalid_state_transition', $transitionContext['reason']);
        $this->assertSame('start', $transitionContext['action']);
    }

    public function test_vm_delete_forced_audited_for_admin(): void
    {
        $vm = VirtualMachine::create([
            'user_id' => $this->tenant->id,
            'host_id' => $this->host->id,
            'name' => 'force-delete-vm-'.uniqid(),
            'incus_project' => 'user-'.$this->tenant->id,
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'images:ubuntu/24.04',
        ]);

        $lifecycleService = app(VmLifecycleService::class);
        $lifecycleService->handleActionRequest($vm, 'delete', true, $this->admin);

        $row = $this->assertAuditRow(
            action: 'vm.delete.forced',
            outcome: 'success',
            tenantUserId: $this->tenant->id,
            visibility: 'tenant',
            requestId: 'req-vm-test-1234',
        );

        $context = json_decode((string) $row->context, true);
        $this->assertSame('delete', $context['action']);
        $this->assertTrue($context['forced']);
    }

    public function test_vm_state_changed_listener_records_transitions_with_source_and_operation(): void
    {
        $vm = VirtualMachine::create([
            'user_id' => $this->tenant->id,
            'host_id' => $this->host->id,
            'name' => 'state-vm-'.uniqid(),
            'incus_project' => 'user-'.$this->tenant->id,
            'instance_type' => 'virtual-machine',
            'status' => Provisioning::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'images:ubuntu/24.04',
        ]);

        app(AuditContext::class)->setOperationId(9876);

        // 1. User transition
        $vm->status->transitionTo(Running::class);

        $rowUser = $this->assertAuditRow(
            action: 'vm.state.changed',
            outcome: 'success',
            tenantUserId: $this->tenant->id,
            visibility: 'tenant',
            requestId: 'req-vm-test-1234',
        );
        $ctxUser = json_decode((string) $rowUser->context, true);
        $this->assertSame('provisioning', $ctxUser['previous_state']);
        $this->assertSame('running', $ctxUser['new_state']);
        $this->assertSame('user', $ctxUser['source']);
        $this->assertSame(9876, $ctxUser['operation_id']);

        // 2. Reconcile transition
        VmReconciliationService::setReconciling(true);
        try {
            $vm->status->transitionTo(Stopped::class);
        } finally {
            VmReconciliationService::setReconciling(false);
        }

        $rowReconcile = $this->assertAuditRow(
            action: 'vm.state.changed',
            outcome: 'success',
            tenantUserId: $this->tenant->id,
            visibility: 'tenant',
            requestId: 'req-vm-test-1234',
        );
        $ctxReconcile = json_decode((string) $rowReconcile->context, true);
        $this->assertSame('running', $ctxReconcile['previous_state']);
        $this->assertSame('stopped', $ctxReconcile['new_state']);
        $this->assertSame('reconcile', $ctxReconcile['source']);
    }
}
