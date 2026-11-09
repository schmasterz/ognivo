<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\AdminVirtualMachineStatusUpdated;
use App\Events\VirtualMachineStatusUpdated;
use App\Models\DriftReport;
use App\Models\Host;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\Hypervisor\Contracts\HypervisorDriverInterface;
use App\Services\Hypervisor\Drivers\FakeHypervisorDriver;
use App\Services\Hypervisor\HypervisorManager;
use App\Services\Hypervisor\IncusEventSyncService;
use App\Services\Hypervisor\Transports\FakeIncusEventTransport;
use App\Services\VmReconciliationService;
use App\States\Vm\Deleted;
use App\States\Vm\Running;
use App\States\Vm\Stopped;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class IncusEventSyncAndReconcileTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $customer;

    protected Host $host;

    protected function setUp(): void
    {
        parent::setUp();

        FakeIncusEventTransport::reset();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $this->admin = User::factory()->create(['status' => 'active']);
        $this->admin->assignRole('admin');

        $this->customer = User::factory()->create([
            'status' => 'active',
            'project_name' => 'tenant-cust-1',
        ]);
        $this->customer->assignRole('customer');

        $this->host = Host::create([
            'name' => 'compute-node-1',
            'endpoint_url' => 'https://127.0.0.1:8443',
            'driver' => 'fake',
            'is_active' => true,
            'status' => 'online',
        ]);
    }

    protected function tearDown(): void
    {
        FakeIncusEventTransport::reset();
        HypervisorManager::resetFake();
        parent::tearDown();
    }

    public function test_incus_event_maps_to_vm_state_changes_and_fires_broadcasts(): void
    {
        Event::fake([
            VirtualMachineStatusUpdated::class,
            AdminVirtualMachineStatusUpdated::class,
        ]);

        $vm = VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $this->host->id,
            'name' => 'web-app-01',
            'incus_project' => 'tenant-cust-1',
            'instance_type' => 'virtual-machine',
            'status' => 'stopped',
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'ubuntu:24.04',
        ]);

        $syncService = app(IncusEventSyncService::class);

        // 1. Simulate instance-started event
        $startEvent = [
            'type' => 'lifecycle',
            'metadata' => [
                'action' => 'instance-started',
                'source' => '/1.0/instances/web-app-01',
                'project' => 'tenant-cust-1',
            ],
        ];

        $updatedVm = $syncService->handleLifecycleEvent($this->host, $startEvent);

        $this->assertNotNull($updatedVm);
        $this->assertInstanceOf(Running::class, $vm->fresh()->status);

        Event::assertDispatched(VirtualMachineStatusUpdated::class, function (VirtualMachineStatusUpdated $event) use ($vm): bool {
            return $event->vm['id'] === $vm->id && $event->vm['status'] === 'running';
        });

        Event::assertDispatched(AdminVirtualMachineStatusUpdated::class, function (AdminVirtualMachineStatusUpdated $event) use ($vm): bool {
            return $event->vm['id'] === $vm->id && $event->vm['status'] === 'running';
        });

        // 2. Simulate instance-stopped event
        $stopEvent = [
            'type' => 'lifecycle',
            'metadata' => [
                'action' => 'instance-stopped',
                'source' => '/1.0/instances/web-app-01',
                'project' => 'tenant-cust-1',
            ],
        ];

        $syncService->handleLifecycleEvent($this->host, $stopEvent);
        $this->assertInstanceOf(Stopped::class, $vm->fresh()->status);

        // 3. Simulate instance-deleted event
        $deleteEvent = [
            'type' => 'lifecycle',
            'metadata' => [
                'action' => 'instance-deleted',
                'source' => '/1.0/instances/web-app-01',
                'project' => 'tenant-cust-1',
            ],
        ];

        $syncService->handleLifecycleEvent($this->host, $deleteEvent);
        $this->assertInstanceOf(Deleted::class, $vm->fresh()->status);
    }

    public function test_artisan_incus_listen_command_processes_fake_gateway_transport_events(): void
    {
        $vm = VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $this->host->id,
            'name' => 'worker-node-1',
            'incus_project' => 'tenant-cust-1',
            'instance_type' => 'virtual-machine',
            'status' => 'stopped',
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'debian:12',
        ]);

        // Queue fake event in transport
        FakeIncusEventTransport::setEvents([
            [
                'type' => 'lifecycle',
                'metadata' => [
                    'action' => 'instance-started',
                    'source' => '/1.0/instances/worker-node-1',
                    'project' => 'tenant-cust-1',
                ],
            ],
        ]);

        Artisan::call('incus:listen', ['host' => $this->host->id]);

        $this->assertInstanceOf(Running::class, $vm->fresh()->status);
    }

    public function test_reconcile_detects_missing_in_db_drift(): void
    {
        $fakeDriver = new FakeHypervisorDriver;
        $fakeDriver->projects = ['tenant-cust-1'];
        $fakeDriver->instances = [
            [
                'name' => 'untracked-cli-vm',
                'project' => 'tenant-cust-1',
                'status' => 'Running',
            ],
        ];

        HypervisorManager::fake($fakeDriver);

        $service = app(VmReconciliationService::class);
        $stats = $service->reconcile($this->host);

        $this->assertSame(1, $stats['drifts_detected']);

        $this->assertDatabaseHas('drift_reports', [
            'host_id' => $this->host->id,
            'instance_name' => 'untracked-cli-vm',
            'incus_project' => 'tenant-cust-1',
            'kind' => 'missing_in_db',
            'resolved_at' => null,
        ]);
    }

    public function test_reconcile_detects_missing_on_host_drift_without_auto_delete(): void
    {
        $vm = VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $this->host->id,
            'name' => 'ghost-vm-in-db',
            'incus_project' => 'tenant-cust-1',
            'instance_type' => 'virtual-machine',
            'status' => 'running',
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu:24.04',
        ]);

        $fakeDriver = new FakeHypervisorDriver;
        $fakeDriver->projects = ['tenant-cust-1'];
        $fakeDriver->instances = []; // Host has 0 instances
        HypervisorManager::fake($fakeDriver);

        $service = app(VmReconciliationService::class);
        $stats = $service->reconcile($this->host);

        $this->assertSame(1, $stats['drifts_detected']);

        $this->assertDatabaseHas('drift_reports', [
            'host_id' => $this->host->id,
            'virtual_machine_id' => $vm->id,
            'instance_name' => 'ghost-vm-in-db',
            'incus_project' => 'tenant-cust-1',
            'kind' => 'missing_on_host',
            'expected_state' => 'running',
            'actual_state' => null,
            'resolved_at' => null,
        ]);

        // Assert NEVER auto-delete: VM is still in the database!
        $this->assertDatabaseHas('virtual_machines', [
            'id' => $vm->id,
            'name' => 'ghost-vm-in-db',
        ]);
    }

    public function test_reconcile_detects_state_mismatch_drift_using_get_instance_status(): void
    {
        $vm = VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $this->host->id,
            'name' => 'desync-vm',
            'incus_project' => 'tenant-cust-1',
            'instance_type' => 'virtual-machine',
            'status' => 'running', // DB expects running
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu:24.04',
        ]);

        $fakeDriver = new FakeHypervisorDriver;
        $fakeDriver->projects = ['tenant-cust-1'];
        $fakeDriver->instances = [
            [
                'name' => 'desync-vm',
                'project' => 'tenant-cust-1',
                'status' => 'Stopped',
            ],
        ];
        // getInstanceStatus returns 'Stopped'
        $fakeDriver->statuses['desync-vm'] = 'Stopped';
        HypervisorManager::fake($fakeDriver);

        $service = app(VmReconciliationService::class);
        $stats = $service->reconcile($this->host);

        $this->assertSame(1, $stats['drifts_detected']);

        $this->assertDatabaseHas('drift_reports', [
            'host_id' => $this->host->id,
            'virtual_machine_id' => $vm->id,
            'instance_name' => 'desync-vm',
            'incus_project' => 'tenant-cust-1',
            'kind' => 'state_mismatch',
            'expected_state' => 'running',
            'actual_state' => 'Stopped',
            'resolved_at' => null,
        ]);
    }

    public function test_reconcile_resolves_previous_drift_when_state_matches(): void
    {
        $vm = VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $this->host->id,
            'name' => 'repaired-vm',
            'incus_project' => 'tenant-cust-1',
            'instance_type' => 'virtual-machine',
            'status' => 'running',
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu:24.04',
        ]);

        // Pre-existing open drift report
        $drift = DriftReport::create([
            'host_id' => $this->host->id,
            'virtual_machine_id' => $vm->id,
            'instance_name' => 'repaired-vm',
            'incus_project' => 'tenant-cust-1',
            'kind' => 'state_mismatch',
            'expected_state' => 'running',
            'actual_state' => 'Stopped',
            'resolved_at' => null,
        ]);

        $fakeDriver = new FakeHypervisorDriver;
        $fakeDriver->projects = ['tenant-cust-1'];
        $fakeDriver->instances = [
            [
                'name' => 'repaired-vm',
                'project' => 'tenant-cust-1',
                'status' => 'Running',
            ],
        ];
        $fakeDriver->statuses['repaired-vm'] = 'Running';
        HypervisorManager::fake($fakeDriver);

        $service = app(VmReconciliationService::class);
        $stats = $service->reconcile($this->host);

        $this->assertSame(1, $stats['drifts_resolved']);
        $this->assertNotNull($drift->fresh()->resolved_at);
    }

    public function test_admin_can_access_drift_reports_and_badge_counter(): void
    {
        DriftReport::create([
            'host_id' => $this->host->id,
            'instance_name' => 'vm-drift-1',
            'incus_project' => 'default',
            'kind' => 'missing_in_db',
            'resolved_at' => null,
        ]);

        DriftReport::create([
            'host_id' => $this->host->id,
            'instance_name' => 'vm-drift-2',
            'incus_project' => 'default',
            'kind' => 'state_mismatch',
            'resolved_at' => now(), // Resolved
        ]);

        // Admin access GET /api/admin/drift
        $response = $this->actingAs($this->admin)->getJson('/api/admin/drift');
        $response->assertOk()
            ->assertJsonPath('meta.unresolved_count', 1)
            ->assertJsonCount(1, 'data'); // Default 'unresolved' status filter

        // Admin badge count
        $badgeResponse = $this->actingAs($this->admin)->getJson('/api/admin/drift/badge');
        $badgeResponse->assertOk()
            ->assertJson([
                'count' => 1,
            ]);

        // Customer access is forbidden
        $forbidden = $this->actingAs($this->customer)->getJson('/api/admin/drift');
        $forbidden->assertForbidden();
    }

    public function test_admin_can_manually_resolve_drift(): void
    {
        $drift = DriftReport::create([
            'host_id' => $this->host->id,
            'instance_name' => 'manual-resolve-vm',
            'incus_project' => 'default',
            'kind' => 'missing_in_db',
            'resolved_at' => null,
        ]);

        $response = $this->actingAs($this->admin)->postJson('/api/admin/drift/'.$drift->id.'/resolve');
        $response->assertOk();

        $this->assertNotNull($drift->fresh()->resolved_at);
    }

    public function test_reconcile_handles_unreachable_host_gracefully_without_false_missing_reports(): void
    {
        $vm = VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $this->host->id,
            'name' => 'offline-host-vm',
            'incus_project' => 'tenant-cust-1',
            'instance_type' => 'virtual-machine',
            'status' => 'running',
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu:24.04',
        ]);

        $mockDriver = \Mockery::mock(HypervisorDriverInterface::class);
        $mockDriver->shouldReceive('listProjects')
            ->once()
            ->andThrow(new \RuntimeException('Connection refused: compute node offline'));

        HypervisorManager::fake($mockDriver);

        $service = app(VmReconciliationService::class);
        $stats = $service->reconcile($this->host);

        // Does NOT create false missing_on_host reports
        $this->assertSame(0, $stats['drifts_detected']);
        $this->assertDatabaseMissing('drift_reports', [
            'kind' => 'missing_on_host',
        ]);

        // Host is safely marked offline
        $this->assertSame('offline', $this->host->fresh()->status);
    }
}
