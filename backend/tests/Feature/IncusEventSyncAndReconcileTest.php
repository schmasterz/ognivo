<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\AdminVirtualMachineStatusUpdated;
use App\Events\VirtualMachineStatusUpdated;
use App\Exceptions\FingerprintMismatchException;
use App\Exceptions\MissingPinException;
use App\Models\DriftReport;
use App\Models\Host;
use App\Models\IpAllocation;
use App\Models\IpPool;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\Hypervisor\Contracts\HypervisorDriverInterface;
use App\Services\Hypervisor\Drivers\FakeHypervisorDriver;
use App\Services\Hypervisor\HypervisorGateway;
use App\Services\Hypervisor\HypervisorManager;
use App\Services\Hypervisor\IncusEventSyncService;
use App\Services\Hypervisor\Transports\FakeIncusEventTransport;
use App\Services\Hypervisor\Transports\WebSocketIncusEventTransport;
use App\Services\PanelCertificateService;
use App\Services\VmReconciliationService;
use App\States\Vm\Deleted;
use App\States\Vm\Provisioning;
use App\States\Vm\Running;
use App\States\Vm\Stopped;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use OpenSSLCertificate;
use RuntimeException;
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

    public function test_instance_deleted_event_releases_allocated_ip_and_ignores_provisioning_vm(): void
    {
        $pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'pool-event-sync',
            'cidr' => '10.60.0.0/24',
            'first_usable' => '10.60.0.10',
            'last_usable' => '10.60.0.50',
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
            'is_public' => true,
        ]);

        $vm = VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $this->host->id,
            'name' => 'vm-with-ip',
            'incus_project' => 'tenant-cust-1',
            'instance_type' => 'virtual-machine',
            'status' => 'running',
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu:24.04',
            'ipv4_address' => '10.60.0.15',
            'network_status' => 'assigned',
        ]);

        $rawAddress = inet_pton('10.60.0.15');
        $this->assertNotFalse($rawAddress);

        $allocation = IpAllocation::create([
            'pool_id' => $pool->id,
            'address' => $rawAddress,
            'ip_string' => '10.60.0.15',
            'status' => 'allocated',
            'user_id' => $this->customer->id,
            'virtual_machine_id' => $vm->id,
            'allocated_at' => now(),
        ]);

        $syncService = app(IncusEventSyncService::class);

        // 1. instance-deleted event for running VM releases IP and marks VM as deleted
        $deleteEvent = [
            'type' => 'lifecycle',
            'metadata' => [
                'action' => 'instance-deleted',
                'source' => '/1.0/instances/vm-with-ip',
                'project' => 'tenant-cust-1',
            ],
        ];

        $syncService->handleLifecycleEvent($this->host, $deleteEvent);

        $this->assertInstanceOf(Deleted::class, $vm->fresh()->status);
        $this->assertNull($vm->fresh()->ipv4_address);

        $allocation->refresh();
        $this->assertSame('cooling', $allocation->status);
        $this->assertNull($allocation->virtual_machine_id);
        $this->assertNotNull($allocation->released_at);

        // 2. instance-deleted event for VM in provisioning status is ignored
        $provisioningVm = VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $this->host->id,
            'name' => 'vm-provisioning',
            'incus_project' => 'tenant-cust-1',
            'instance_type' => 'virtual-machine',
            'status' => 'provisioning',
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu:24.04',
        ]);

        $deleteProvisioningEvent = [
            'type' => 'lifecycle',
            'metadata' => [
                'action' => 'instance-deleted',
                'source' => '/1.0/instances/vm-provisioning',
                'project' => 'tenant-cust-1',
            ],
        ];

        $syncService->handleLifecycleEvent($this->host, $deleteProvisioningEvent);

        $this->assertInstanceOf(Provisioning::class, $provisioningVm->fresh()->status);
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

    public function test_incus_listen_command_processes_events_for_multiple_active_hosts(): void
    {
        $host2 = Host::create([
            'name' => 'compute-node-2',
            'endpoint_url' => 'https://127.0.0.2:8443',
            'driver' => 'fake',
            'is_active' => true,
            'status' => 'online',
        ]);

        $vm1 = VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $this->host->id,
            'name' => 'worker-node-multi-1',
            'incus_project' => 'tenant-cust-1',
            'instance_type' => 'virtual-machine',
            'status' => 'stopped',
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'debian:12',
        ]);

        $vm2 = VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $host2->id,
            'name' => 'worker-node-multi-2',
            'incus_project' => 'tenant-cust-1',
            'instance_type' => 'virtual-machine',
            'status' => 'stopped',
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'debian:12',
        ]);

        $multiHostTransport = new class extends FakeIncusEventTransport
        {
            /** @var array<int, list<array<string, mixed>>> */
            public array $eventsByHost = [];

            /** @var list<int> */
            public array $listenedHostIds = [];

            public function listen(Host $host, callable $onEvent, ?callable $shouldStop = null): void
            {
                $this->listenedHostIds[] = $host->id;
                $events = $this->eventsByHost[$host->id] ?? [];
                foreach ($events as $event) {
                    $onEvent($event);
                }
            }
        };

        $multiHostTransport->eventsByHost[$this->host->id] = [
            [
                'type' => 'lifecycle',
                'metadata' => [
                    'action' => 'instance-started',
                    'source' => '/1.0/instances/worker-node-multi-1',
                    'project' => 'tenant-cust-1',
                ],
            ],
        ];

        $multiHostTransport->eventsByHost[$host2->id] = [
            [
                'type' => 'lifecycle',
                'metadata' => [
                    'action' => 'instance-started',
                    'source' => '/1.0/instances/worker-node-multi-2',
                    'project' => 'tenant-cust-1',
                ],
            ],
        ];

        $this->app->instance(FakeIncusEventTransport::class, $multiHostTransport);

        // Run multi-host listener in daemon mode (no host argument)
        Artisan::call('incus:listen');

        $this->assertContains($this->host->id, $multiHostTransport->listenedHostIds);
        $this->assertContains($host2->id, $multiHostTransport->listenedHostIds);
        $this->assertInstanceOf(Running::class, $vm1->fresh()->status);
        $this->assertInstanceOf(Running::class, $vm2->fresh()->status);
    }

    public function test_invalid_state_transition_in_incus_event_sync_service_logs_warning_and_does_not_throw(): void
    {
        Log::spy();

        // Create a VM in 'deleted' state (which has no valid transition to 'running')
        $vm = VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $this->host->id,
            'name' => 'already-deleted-vm',
            'incus_project' => 'tenant-cust-1',
            'instance_type' => 'virtual-machine',
            'status' => 'deleted',
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'debian:12',
        ]);

        $syncService = app(IncusEventSyncService::class);

        $startEvent = [
            'type' => 'lifecycle',
            'metadata' => [
                'action' => 'instance-started',
                'source' => '/1.0/instances/already-deleted-vm',
                'project' => 'tenant-cust-1',
            ],
        ];

        // Should NOT throw TransitionNotFound exception
        $result = $syncService->handleLifecycleEvent($this->host, $startEvent);

        $this->assertNotNull($result);
        $this->assertSame($vm->id, $result->id);
        $this->assertInstanceOf(Deleted::class, $vm->fresh()->status);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function ($message, $context = null): bool {
                return str_contains((string) $message, 'Invalid state transition')
                    && is_array($context)
                    && isset($context['exception'])
                    && $context['exception'] instanceof \Throwable;
            });
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
            ->andThrow(new RuntimeException('Connection refused: compute node offline'));

        HypervisorManager::fake($mockDriver);

        $service = app(VmReconciliationService::class);
        $stats = $service->reconcile($this->host);

        // Does NOT create false missing_on_host reports
        $this->assertSame(0, $stats['drifts_detected']);
        $this->assertDatabaseMissing('drift_reports', [
            'kind' => 'missing_on_host',
        ]);

        // Host is safely marked unreachable
        $this->assertSame('unreachable', $this->host->fresh()->status);

        // Host recovers to ok when reachable driver responds
        $fakeDriver = new FakeHypervisorDriver;
        $fakeDriver->projects = ['tenant-cust-1'];
        $fakeDriver->instances = [
            [
                'name' => 'offline-host-vm',
                'project' => 'tenant-cust-1',
                'status' => 'Running',
            ],
        ];
        HypervisorManager::fake($fakeDriver);

        $service->reconcile($this->host);
        $this->assertSame('ok', $this->host->fresh()->status);
    }

    public function test_reconcile_skips_missing_on_host_when_list_instances_fails_for_project(): void
    {
        VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $this->host->id,
            'name' => 'surviving-vm',
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
            ->andReturn(['tenant-cust-1']);
        $mockDriver->shouldReceive('listInstances')
            ->with('tenant-cust-1')
            ->once()
            ->andThrow(new RuntimeException('Connection timed out while querying project tenant-cust-1'));

        HypervisorManager::fake($mockDriver);

        $service = app(VmReconciliationService::class);
        $stats = $service->reconcile($this->host);

        // Host stays online because listProjects succeeded
        $this->assertSame('online', $this->host->fresh()->status);
        // Does NOT report false missing_on_host for surviving-vm
        $this->assertSame(0, $stats['drifts_detected']);
        $this->assertDatabaseMissing('drift_reports', [
            'host_id' => $this->host->id,
            'instance_name' => 'surviving-vm',
            'kind' => 'missing_on_host',
        ]);
    }

    public function test_reconcile_ignores_unmanaged_host_projects_for_missing_in_db(): void
    {
        $fakeDriver = new FakeHypervisorDriver;
        $fakeDriver->projects = ['default', 'tenant-cust-1'];
        $fakeDriver->instances = [
            [
                'name' => 'unmanaged-system-vm',
                'project' => 'default',
                'status' => 'Running',
            ],
        ];

        HypervisorManager::fake($fakeDriver);

        $service = app(VmReconciliationService::class);
        $stats = $service->reconcile($this->host);

        $this->assertSame(0, $stats['drifts_detected']);
        $this->assertDatabaseMissing('drift_reports', [
            'instance_name' => 'unmanaged-system-vm',
            'kind' => 'missing_in_db',
        ]);
    }

    public function test_reconcile_auto_resolves_open_missing_in_db_and_missing_on_host_drifts(): void
    {
        // 1. Open missing_in_db drift for an instance no longer on the host
        $openMissingInDbDrift = DriftReport::create([
            'host_id' => $this->host->id,
            'instance_name' => 'deleted-external-vm',
            'incus_project' => 'tenant-cust-1',
            'kind' => 'missing_in_db',
            'actual_state' => 'Running',
            'resolved_at' => null,
        ]);

        // 2. Open missing_in_db drift for an instance that is now in DB
        VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $this->host->id,
            'name' => 'now-registered-vm',
            'incus_project' => 'tenant-cust-1',
            'instance_type' => 'virtual-machine',
            'status' => 'running',
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu:24.04',
        ]);

        $openMissingInDbNowInDb = DriftReport::create([
            'host_id' => $this->host->id,
            'instance_name' => 'now-registered-vm',
            'incus_project' => 'tenant-cust-1',
            'kind' => 'missing_in_db',
            'actual_state' => 'Running',
            'resolved_at' => null,
        ]);

        // 3. Open missing_on_host drift for a VM that is now present on the host
        $restoredVm = VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $this->host->id,
            'name' => 'restored-vm',
            'incus_project' => 'tenant-cust-1',
            'instance_type' => 'virtual-machine',
            'status' => 'running',
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu:24.04',
        ]);

        $openMissingOnHostDrift = DriftReport::create([
            'host_id' => $this->host->id,
            'virtual_machine_id' => $restoredVm->id,
            'instance_name' => 'restored-vm',
            'incus_project' => 'tenant-cust-1',
            'kind' => 'missing_on_host',
            'expected_state' => 'running',
            'resolved_at' => null,
        ]);

        // Host currently has now-registered-vm and restored-vm (both running). deleted-external-vm is gone!
        $fakeDriver = new FakeHypervisorDriver;
        $fakeDriver->projects = ['tenant-cust-1'];
        $fakeDriver->instances = [
            [
                'name' => 'now-registered-vm',
                'project' => 'tenant-cust-1',
                'status' => 'Running',
            ],
            [
                'name' => 'restored-vm',
                'project' => 'tenant-cust-1',
                'status' => 'Running',
            ],
        ];
        $fakeDriver->statuses['now-registered-vm'] = 'Running';
        $fakeDriver->statuses['restored-vm'] = 'Running';
        HypervisorManager::fake($fakeDriver);

        $service = app(VmReconciliationService::class);
        $stats = $service->reconcile($this->host);

        $this->assertSame(3, $stats['drifts_resolved']);
        $this->assertNotNull($openMissingInDbDrift->fresh()->resolved_at);
        $this->assertNotNull($openMissingInDbNowInDb->fresh()->resolved_at);
        $this->assertNotNull($openMissingOnHostDrift->fresh()->resolved_at);
    }

    public function test_reconcile_skips_provisioning_and_failed_vms_for_missing_and_mismatch(): void
    {
        // VM 1: provisioning, not on host -> should NOT generate missing_on_host
        $provisioningVm = VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $this->host->id,
            'name' => 'provisioning-vm',
            'incus_project' => 'tenant-cust-1',
            'instance_type' => 'virtual-machine',
            'status' => 'provisioning',
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu:24.04',
        ]);

        // VM 2: failed, not on host -> should NOT generate missing_on_host
        $failedVm = VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $this->host->id,
            'name' => 'failed-vm',
            'incus_project' => 'tenant-cust-1',
            'instance_type' => 'virtual-machine',
            'status' => 'failed',
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu:24.04',
        ]);

        // VM 3: provisioning, present on host with stopped state -> should NOT generate state_mismatch
        $provisioningOnHost = VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $this->host->id,
            'name' => 'provisioning-on-host',
            'incus_project' => 'tenant-cust-1',
            'instance_type' => 'virtual-machine',
            'status' => 'provisioning',
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu:24.04',
        ]);

        // VM 4: failed, present on host with running state -> should NOT generate state_mismatch
        $failedOnHost = VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $this->host->id,
            'name' => 'failed-on-host',
            'incus_project' => 'tenant-cust-1',
            'instance_type' => 'virtual-machine',
            'status' => 'failed',
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu:24.04',
        ]);

        $fakeDriver = new FakeHypervisorDriver;
        $fakeDriver->projects = ['tenant-cust-1'];
        $fakeDriver->instances = [
            [
                'name' => 'provisioning-on-host',
                'project' => 'tenant-cust-1',
                'status' => 'Stopped',
            ],
            [
                'name' => 'failed-on-host',
                'project' => 'tenant-cust-1',
                'status' => 'Running',
            ],
        ];
        $fakeDriver->statuses['provisioning-on-host'] = 'Stopped';
        $fakeDriver->statuses['failed-on-host'] = 'Running';
        HypervisorManager::fake($fakeDriver);

        $service = app(VmReconciliationService::class);
        $stats = $service->reconcile($this->host);

        $this->assertSame(0, $stats['drifts_detected']);
        $this->assertDatabaseMissing('drift_reports', [
            'host_id' => $this->host->id,
            'virtual_machine_id' => $provisioningVm->id,
        ]);
        $this->assertDatabaseMissing('drift_reports', [
            'host_id' => $this->host->id,
            'virtual_machine_id' => $failedVm->id,
        ]);
        $this->assertDatabaseMissing('drift_reports', [
            'host_id' => $this->host->id,
            'virtual_machine_id' => $provisioningOnHost->id,
        ]);
        $this->assertDatabaseMissing('drift_reports', [
            'host_id' => $this->host->id,
            'virtual_machine_id' => $failedOnHost->id,
        ]);
    }

    public function test_websocket_upgrade_request_includes_all_projects_parameter(): void
    {
        $certData = $this->generateTlsCertAndKey('127.0.0.1');
        $requestFile = tempnam(sys_get_temp_dir(), 'test_ws_req_');
        $this->assertNotFalse($requestFile);

        $server = $this->startLocalTlsServer($certData['certPem'], $certData['keyPem'], function ($conn) use ($requestFile): void {
            $req = @fread($conn, 4096);
            if ($req !== false) {
                file_put_contents($requestFile, $req);
            }
            @fwrite($conn, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n\r\n");
            @fclose($conn);
        });

        try {
            $host = Host::create([
                'name' => 'tls-host-upgrade-test',
                'endpoint_url' => 'https://'.$server['address'],
                'driver' => 'incus',
                'server_public_key_pin' => $certData['pin'],
                'is_active' => true,
                'status' => 'online',
            ]);

            app(PanelCertificateService::class)->generate(365);
            $transport = app(WebSocketIncusEventTransport::class);

            $transport->connectAndStream($host, function (): void {}, function (): bool {
                return true; // Stop immediately after upgrade
            });

            $requestContent = file_get_contents($requestFile);
            $this->assertIsString($requestContent);
            $this->assertStringContainsString('GET /1.0/events?type=lifecycle&all-projects=true HTTP/1.1', $requestContent);
        } finally {
            $this->stopLocalTlsServer($server);
            if (file_exists($requestFile)) {
                @unlink($requestFile);
            }
        }
    }

    public function test_websocket_fails_closed_when_pin_and_fingerprint_are_missing_for_non_fake_host(): void
    {
        $certData = $this->generateTlsCertAndKey('127.0.0.1');
        $server = $this->startLocalTlsServer($certData['certPem'], $certData['keyPem']);

        try {
            $host = Host::create([
                'name' => 'tls-host-no-pin',
                'endpoint_url' => 'https://'.$server['address'],
                'driver' => 'incus',
                'server_public_key_pin' => null,
                'server_fingerprint' => null,
                'is_active' => true,
                'status' => 'online',
            ]);

            app(PanelCertificateService::class)->generate(365);
            $transport = app(WebSocketIncusEventTransport::class);

            try {
                $transport->connectAndStream($host, function (): void {});
                $this->fail('Expected MissingPinException was not thrown.');
            } catch (MissingPinException $e) {
                $this->assertStringContainsString('requires a public key pin in operational mode', $e->getMessage());
            }

            $host->refresh();
            $this->assertSame('not_trusted', $host->status);
        } finally {
            $this->stopLocalTlsServer($server);
        }
    }

    public function test_websocket_fails_closed_when_pin_mismatches_for_non_fake_host(): void
    {
        $certData = $this->generateTlsCertAndKey('127.0.0.1');
        $server = $this->startLocalTlsServer($certData['certPem'], $certData['keyPem']);

        try {
            $host = Host::create([
                'name' => 'tls-host-pin-mismatch',
                'endpoint_url' => 'https://'.$server['address'],
                'driver' => 'incus',
                'server_public_key_pin' => 'sha256//'.base64_encode('WRONG_PUBLIC_KEY_PIN'),
                'is_active' => true,
                'status' => 'online',
            ]);

            app(PanelCertificateService::class)->generate(365);
            $transport = app(WebSocketIncusEventTransport::class);

            try {
                $transport->connectAndStream($host, function (): void {});
                $this->fail('Expected FingerprintMismatchException was not thrown.');
            } catch (FingerprintMismatchException $e) {
                $this->assertStringContainsString('Server certificate public key does not match pinned public key', $e->getMessage());
            }

            $host->refresh();
            $this->assertSame('fingerprint_mismatch', $host->status);
        } finally {
            $this->stopLocalTlsServer($server);
        }
    }

    public function test_websocket_fails_closed_when_peer_certificate_is_missing_for_non_fake_host(): void
    {
        $certData = $this->generateTlsCertAndKey('127.0.0.1');
        $server = $this->startLocalTlsServer($certData['certPem'], $certData['keyPem']);

        try {
            $host = Host::create([
                'name' => 'tls-host-missing-peer-cert',
                'endpoint_url' => 'https://'.$server['address'],
                'driver' => 'incus',
                'server_public_key_pin' => $certData['pin'],
                'is_active' => true,
                'status' => 'online',
            ]);

            app(PanelCertificateService::class)->generate(365);

            $transport = new class extends WebSocketIncusEventTransport
            {
                protected function capturePeerCertificate($socket): ?OpenSSLCertificate
                {
                    return null;
                }
            };

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Failed to capture peer certificate for host [tls-host-missing-peer-cert].');

            $transport->connectAndStream($host, function (): void {});
        } finally {
            $this->stopLocalTlsServer($server);
        }
    }

    /**
     * @return array{certPem: string, keyPem: string, pin: string, fingerprint: string}
     */
    private function generateTlsCertAndKey(string $commonName = '127.0.0.1'): array
    {
        $privkey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($privkey === false) {
            throw new RuntimeException('Failed to generate private key.');
        }

        $csr = openssl_csr_new(['commonName' => $commonName], $privkey);
        if ($csr === false) {
            throw new RuntimeException('Failed to generate CSR.');
        }

        $cert = openssl_csr_sign($csr, null, $privkey, 365);
        if ($cert === false) {
            throw new RuntimeException('Failed to sign cert.');
        }

        $certPem = '';
        $keyPem = '';
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($privkey, $keyPem);

        $pin = HypervisorGateway::computePinFromCertificate($certPem);
        $fingerprint = strtolower((string) openssl_x509_fingerprint($certPem, 'sha256'));

        return [
            'certPem' => $certPem,
            'keyPem' => $keyPem,
            'pin' => $pin,
            'fingerprint' => $fingerprint,
        ];
    }

    /**
     * Start a local TLS server on an ephemeral loopback port in a child process.
     *
     * @param  (callable(resource, string): void)|null  $handler
     * @return array{address: string, pid: int, certFile: string, keyFile: string}
     */
    private function startLocalTlsServer(string $certPem, string $keyPem, ?callable $handler = null): array
    {
        $certFile = tempnam(sys_get_temp_dir(), 'test_cert_');
        $keyFile = tempnam(sys_get_temp_dir(), 'test_key_');
        if ($certFile === false || $keyFile === false) {
            throw new RuntimeException('Failed to allocate temporary files.');
        }

        file_put_contents($certFile, $certPem);
        file_put_contents($keyFile, $keyPem);

        $context = stream_context_create([
            'ssl' => [
                'local_cert' => $certFile,
                'local_pk' => $keyFile,
                'verify_peer' => false,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $server = stream_socket_server('ssl://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        if ($server === false) {
            throw new RuntimeException("Failed to bind local TLS server: $errstr");
        }

        $address = stream_socket_get_name($server, false);
        if ($address === false) {
            throw new RuntimeException('Failed to obtain server address.');
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Failed to fork test server process.');
        }

        if ($pid === 0) {
            $conn = @stream_socket_accept($server, 10);
            if ($conn) {
                if ($handler !== null) {
                    $handler($conn, $address);
                } else {
                    @fread($conn, 4096);
                    @fwrite($conn, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n\r\n");
                    @fclose($conn);
                }
            }
            @fclose($server);
            exit(0);
        }

        @fclose($server);
        usleep(50000);

        return [
            'address' => $address,
            'pid' => $pid,
            'certFile' => $certFile,
            'keyFile' => $keyFile,
        ];
    }

    /**
     * Stop the local TLS server child process and clean up temporary files.
     *
     * @param  array{address: string, pid: int, certFile: string, keyFile: string}  $serverInfo
     */
    private function stopLocalTlsServer(array $serverInfo): void
    {
        @posix_kill($serverInfo['pid'], SIGKILL);
        $status = 0;
        @pcntl_waitpid($serverInfo['pid'], $status);
        if (file_exists($serverInfo['certFile'])) {
            @unlink($serverInfo['certFile']);
        }
        if (file_exists($serverInfo['keyFile'])) {
            @unlink($serverInfo['keyFile']);
        }
    }
}
