<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\AuditContext;
use App\Models\Host;
use App\Models\QuotaGroup;
use App\Models\User;
use App\Models\UserQuota;
use App\Models\VirtualMachine;
use App\Models\VmSnapshot;
use App\Services\Hypervisor\Drivers\FakeHypervisorDriver;
use App\Services\Hypervisor\HypervisorManager;
use App\Services\VmSnapshotService;
use App\States\Vm\Stopped;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AuditSnapshotCoverageTest extends TestCase
{
    use AuditAssertionTrait;
    use RefreshDatabase;

    private User $customer;

    private Host $host;

    private VirtualMachine $vm;

    private VmSnapshotService $snapshotService;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Event::fake();

        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $group = QuotaGroup::firstOrCreate(
            ['name' => 'Snapshot Test Tier'],
            [
                'is_default' => false,
                'max_vms' => 5,
                'max_cpu_cores' => 8,
                'max_ram_mb' => 8192,
                'max_storage_gb' => 100,
                'max_ips' => 2,
                'max_snapshots' => 3,
            ]
        );

        $this->customer = User::factory()->create([
            'status' => 'active',
            'quota_group_id' => $group->id,
        ]);
        $this->customer->assignRole('customer');

        UserQuota::create([
            'user_id' => $this->customer->id,
            'quota_group_id' => $group->id,
            'max_vms' => 5,
            'max_cpu_cores' => 8,
            'max_ram_mb' => 8192,
            'max_storage_gb' => 100,
            'max_ips' => 2,
            'max_snapshots' => 2,
        ]);

        $this->host = Host::create([
            'name' => 'snap-test-host-'.uniqid(),
            'endpoint_url' => 'https://127.0.0.1:8443',
            'driver' => 'fake',
            'is_active' => true,
            'status' => 'ok',
        ]);

        $this->vm = VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $this->host->id,
            'name' => 'vm-snap-test-'.uniqid(),
            'incus_project' => 'user-'.$this->customer->id,
            'instance_type' => 'virtual-machine',
            'status' => Stopped::class,
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'images:ubuntu/24.04',
        ]);

        HypervisorManager::fake(new FakeHypervisorDriver);
        $this->snapshotService = app(VmSnapshotService::class);
        app(AuditContext::class)->setRequestId('req-snap-test-9999');
    }

    protected function tearDown(): void
    {
        HypervisorManager::resetFake();
        parent::tearDown();
    }

    public function test_vm_snapshot_created_restored_and_deleted_audited(): void
    {
        // 1. Create snapshot
        $snapshot = $this->snapshotService->create($this->vm, 'snap-alpha');

        $createRow = $this->assertAuditRow(
            action: 'vm.snapshot.created',
            outcome: 'success',
            tenantUserId: $this->customer->id,
            visibility: 'tenant',
            requestId: 'req-snap-test-9999',
        );
        $createContext = json_decode((string) $createRow->context, true);
        $this->assertSame('snap-alpha', $createContext['name']);
        $this->assertSame($this->vm->id, $createContext['virtual_machine_id']);

        // Mark snapshot ready for restore
        $snapshot->status = VmSnapshot::STATUS_READY;
        $snapshot->save();

        // 2. Restore snapshot
        $this->snapshotService->restore($snapshot);

        $restoreRow = $this->assertAuditRow(
            action: 'vm.snapshot.restored',
            outcome: 'success',
            tenantUserId: $this->customer->id,
            visibility: 'tenant',
            requestId: 'req-snap-test-9999',
        );
        $restoreContext = json_decode((string) $restoreRow->context, true);
        $this->assertSame('snap-alpha', $restoreContext['name']);
        $this->assertSame($this->vm->id, $restoreContext['virtual_machine_id']);
        $this->assertSame('stopped', $restoreContext['previous_state']);

        // Mark ready again for deletion
        $snapshot->status = VmSnapshot::STATUS_READY;
        $snapshot->save();

        // 3. Delete snapshot
        $this->snapshotService->delete($snapshot);

        $deleteRow = $this->assertAuditRow(
            action: 'vm.snapshot.deleted',
            outcome: 'success',
            tenantUserId: $this->customer->id,
            visibility: 'tenant',
            requestId: 'req-snap-test-9999',
        );
        $deleteContext = json_decode((string) $deleteRow->context, true);
        $this->assertSame('snap-alpha', $deleteContext['name']);
        $this->assertSame($this->vm->id, $deleteContext['virtual_machine_id']);
    }

    public function test_denied_snapshot_creation_audited_as_vm_action_denied(): void
    {
        // Invalid name format triggers validation and denial
        try {
            $this->snapshotService->create($this->vm, 'INVALID_NAME_WITH_CAPS');
            $this->fail('Expected exception for invalid snapshot name');
        } catch (\Throwable $e) {
            // Expected
        }

        $deniedRow = $this->assertAuditRow(
            action: 'vm.action.denied',
            outcome: 'denied',
            tenantUserId: $this->customer->id,
            visibility: 'tenant',
            requestId: 'req-snap-test-9999',
        );
        $context = json_decode((string) $deniedRow->context, true);
        $this->assertSame('snapshot.create', $context['action']);
        $this->assertSame('INVALID_NAME_WITH_CAPS', $context['name']);
        $this->assertSame($this->vm->id, $context['virtual_machine_id']);
    }
}
