<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\BackupTargetInterface;
use App\Events\SnapshotStatusUpdated;
use App\Exceptions\DuplicateSnapshotNameException;
use App\Exceptions\InvalidSnapshotNameException;
use App\Exceptions\InvalidSnapshotStateException;
use App\Exceptions\InvalidVmStateException;
use App\Exceptions\SnapshotQuotaExceededException;
use App\Jobs\CreateSnapshotJob;
use App\Jobs\DeleteSnapshotJob;
use App\Jobs\RestoreSnapshotJob;
use App\Models\Host;
use App\Models\QuotaGroup;
use App\Models\User;
use App\Models\UserQuota;
use App\Models\VirtualMachine;
use App\Models\VmSnapshot;
use App\Services\Backup\LocalSnapshotTarget;
use App\Services\Hypervisor\Drivers\FakeHypervisorDriver;
use App\Services\Hypervisor\HypervisorManager;
use App\Services\VmSnapshotService;
use App\States\Snapshot\Creating as SnapshotCreating;
use App\States\Snapshot\Deleting as SnapshotDeleting;
use App\States\Snapshot\Failed as SnapshotFailed;
use App\States\Snapshot\Ready as SnapshotReady;
use App\States\Snapshot\Restoring as SnapshotRestoring;
use App\States\Vm\Deleted;
use App\States\Vm\Deleting;
use App\States\Vm\Failed;
use App\States\Vm\Provisioning;
use App\States\Vm\Stopped;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class VmSnapshotServiceAndJobsTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private Host $host;

    private VirtualMachine $vm;

    private FakeHypervisorDriver $fakeDriver;

    private VmSnapshotService $snapshotService;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $defaultGroup = QuotaGroup::where('is_default', true)->firstOrFail();

        $this->customer = User::factory()->create([
            'name' => 'Snapshot Service Tenant',
            'email' => 'tenant@snapshot-service.local',
            'status' => 'active',
            'project_name' => 'tenant-snap-svc',
            'quota_group_id' => $defaultGroup->id,
        ]);
        $this->customer->assignRole('customer');

        UserQuota::create([
            'user_id' => $this->customer->id,
            'quota_group_id' => $defaultGroup->id,
            'max_vms' => 5,
            'max_cpu_cores' => 8,
            'max_ram_mb' => 8192,
            'max_storage_gb' => 100,
            'max_ips' => 2,
            'max_snapshots' => 3,
        ]);

        $this->host = Host::create([
            'name' => 'snap-compute-node',
            'endpoint_url' => 'https://127.0.0.1:8443',
            'driver' => 'fake',
            'is_active' => true,
            'status' => 'ok',
        ]);

        $this->vm = VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $this->host->id,
            'name' => 'vm-snap-flow',
            'incus_project' => 'tenant-snap-svc',
            'instance_type' => 'virtual-machine',
            'status' => Stopped::class,
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'images:ubuntu/24.04',
        ]);

        $this->fakeDriver = new FakeHypervisorDriver;
        HypervisorManager::fake($this->fakeDriver);

        $this->snapshotService = app(VmSnapshotService::class);
    }

    protected function tearDown(): void
    {
        HypervisorManager::resetFake();
        parent::tearDown();
    }

    public function test_backup_target_is_bound_to_local_snapshot_target_in_service_provider(): void
    {
        $resolved = app(BackupTargetInterface::class);
        $this->assertInstanceOf(LocalSnapshotTarget::class, $resolved);
    }

    public function test_full_create_ready_restore_delete_flow_with_fake_hypervisor_driver_and_events(): void
    {
        Event::fake([SnapshotStatusUpdated::class]);
        Queue::fake();

        // Set initial state marker on fake hypervisor before taking snapshot
        $this->fakeDriver->statuses[$this->vm->name] = 'Stopped';
        $this->fakeDriver->setStateMarker($this->vm, 'clean-baseline-v1');

        // 1. Create snapshot -> inserts row with `creating` and dispatches CreateSnapshotJob
        $snapshot = $this->snapshotService->create($this->vm, 'pre-upgrade');

        $this->assertTrue($snapshot->status->equals(SnapshotCreating::class));
        $this->assertDatabaseHas('vm_snapshots', [
            'id' => $snapshot->id,
            'virtual_machine_id' => $this->vm->id,
            'user_id' => $this->customer->id,
            'name' => 'pre-upgrade',
            'status' => VmSnapshot::STATUS_CREATING,
        ]);

        Queue::assertPushed(CreateSnapshotJob::class, fn (CreateSnapshotJob $job): bool => $job->snapshot->is($snapshot));
        Event::assertDispatched(SnapshotStatusUpdated::class, function (SnapshotStatusUpdated $event) use ($snapshot): bool {
            $channels = array_map(static fn ($c): string => (string) $c, $event->broadcastOn());

            return $event->snapshot->is($snapshot)
                && $event->status === VmSnapshot::STATUS_CREATING
                && in_array('private-users.'.$this->customer->id, $channels, true)
                && in_array('private-admin.workloads', $channels, true);
        });

        // 2. Execute CreateSnapshotJob -> transitions to ready and dispatches SnapshotStatusUpdated
        (new CreateSnapshotJob($snapshot))->handle(app(BackupTargetInterface::class));

        $snapshot->refresh();
        $this->assertTrue($snapshot->status->equals(SnapshotReady::class));
        $this->assertNull($snapshot->error);
        $this->assertCount(1, $this->fakeDriver->listSnapshots($this->vm));

        Event::assertDispatched(SnapshotStatusUpdated::class, fn (SnapshotStatusUpdated $event): bool => $event->snapshot->is($snapshot) && $event->status === VmSnapshot::STATUS_READY);

        // Mutate VM state marker on hypervisor to verify restore reverts it
        $this->fakeDriver->setStateMarker($this->vm, 'corrupted-after-upgrade');
        $this->assertSame('corrupted-after-upgrade', $this->fakeDriver->getStateMarker($this->vm));

        // 3. Restore snapshot -> marks `restoring` and dispatches RestoreSnapshotJob
        $this->snapshotService->restore($snapshot);

        $snapshot->refresh();
        $this->assertTrue($snapshot->status->equals(SnapshotRestoring::class));
        Queue::assertPushed(RestoreSnapshotJob::class, fn (RestoreSnapshotJob $job): bool => $job->snapshot->is($snapshot));
        Event::assertDispatched(SnapshotStatusUpdated::class, fn (SnapshotStatusUpdated $event): bool => $event->snapshot->is($snapshot) && $event->status === VmSnapshot::STATUS_RESTORING);

        // 4. Execute RestoreSnapshotJob -> restores on hypervisor, returns status to `ready`, dispatches SnapshotStatusUpdated
        (new RestoreSnapshotJob($snapshot))->handle(app(BackupTargetInterface::class));

        $snapshot->refresh();
        $this->assertTrue($snapshot->status->equals(SnapshotReady::class));
        $this->assertSame('pre-upgrade', $this->fakeDriver->getRestoredSnapshot($this->vm));
        $this->assertSame('clean-baseline-v1', $this->fakeDriver->getStateMarker($this->vm));

        // 5. Delete snapshot -> marks `deleting` and dispatches DeleteSnapshotJob
        $this->snapshotService->delete($snapshot);

        $snapshot->refresh();
        $this->assertTrue($snapshot->status->equals(SnapshotDeleting::class));
        Queue::assertPushed(DeleteSnapshotJob::class, fn (DeleteSnapshotJob $job): bool => $job->snapshot->is($snapshot));
        Event::assertDispatched(SnapshotStatusUpdated::class, fn (SnapshotStatusUpdated $event): bool => $event->snapshot->is($snapshot) && $event->status === VmSnapshot::STATUS_DELETING);

        // 6. Execute DeleteSnapshotJob -> deletes from hypervisor and DB, dispatches SnapshotStatusUpdated
        (new DeleteSnapshotJob($snapshot))->handle(app(BackupTargetInterface::class));

        $this->assertDatabaseMissing('vm_snapshots', ['id' => $snapshot->id]);
        $this->assertSame([], $this->fakeDriver->listSnapshots($this->vm));

        Event::assertDispatched(SnapshotStatusUpdated::class, fn (SnapshotStatusUpdated $event): bool => $event->snapshot->id === $snapshot->id && $event->status === 'deleted');
    }

    public function test_quota_exceeded_throws_clear_domain_exception(): void
    {
        Queue::fake();

        // Customer has max_snapshots = 3
        VmSnapshot::factory()->ready()->create(['virtual_machine_id' => $this->vm->id, 'user_id' => $this->customer->id, 'name' => 'snap-1']);
        VmSnapshot::factory()->ready()->create(['virtual_machine_id' => $this->vm->id, 'user_id' => $this->customer->id, 'name' => 'snap-2']);
        VmSnapshot::factory()->creating()->create(['virtual_machine_id' => $this->vm->id, 'user_id' => $this->customer->id, 'name' => 'snap-3']);

        try {
            $this->snapshotService->create($this->vm, 'snap-4');
            $this->fail('Expected SnapshotQuotaExceededException was not thrown.');
        } catch (SnapshotQuotaExceededException $e) {
            $this->assertInstanceOf(DomainException::class, $e);
            $this->assertStringContainsStringIgnoringCase('quota', $e->getMessage());
        }

        $this->assertDatabaseMissing('vm_snapshots', [
            'virtual_machine_id' => $this->vm->id,
            'name' => 'snap-4',
        ]);
        Queue::assertNotPushed(CreateSnapshotJob::class);
    }

    public function test_duplicate_name_and_invalid_slug_throw_clear_domain_exceptions(): void
    {
        Queue::fake();

        $this->snapshotService->create($this->vm, 'daily-backup');

        try {
            $this->snapshotService->create($this->vm, 'daily-backup');
            $this->fail('Expected DuplicateSnapshotNameException was not thrown.');
        } catch (DuplicateSnapshotNameException $e) {
            $this->assertInstanceOf(DomainException::class, $e);
            $this->assertStringContainsString('daily-backup', $e->getMessage());
        }

        foreach (['', 'Invalid Name!', 'snap/slash', '-leading-dash', 'trailing-dash-'] as $badName) {
            try {
                $this->snapshotService->create($this->vm, $badName);
                $this->fail(sprintf('Expected InvalidSnapshotNameException for "%s" was not thrown.', $badName));
            } catch (InvalidSnapshotNameException $e) {
                $this->assertInstanceOf(DomainException::class, $e);
            }
        }
    }

    public function test_wrong_vm_state_and_wrong_snapshot_state_throw_clear_domain_exceptions(): void
    {
        Queue::fake();

        foreach ([Provisioning::class, Deleting::class, Deleted::class, Failed::class] as $invalidVmState) {
            $vmInBadState = VirtualMachine::create([
                'user_id' => $this->customer->id,
                'host_id' => $this->host->id,
                'name' => 'vm-bad-'.strtolower(class_basename($invalidVmState)),
                'incus_project' => 'tenant-snap-svc',
                'instance_type' => 'virtual-machine',
                'status' => $invalidVmState,
                'cpu_cores' => 1,
                'ram_mb' => 1024,
                'disk_gb' => 10,
                'os_image' => 'images:ubuntu/24.04',
            ]);

            try {
                $this->snapshotService->create($vmInBadState, 'snap-bad-state');
                $this->fail(sprintf('Expected InvalidVmStateException for VM state %s on create.', $invalidVmState));
            } catch (InvalidVmStateException $e) {
                $this->assertInstanceOf(DomainException::class, $e);
            }

            $readySnapOnBadVm = VmSnapshot::factory()->ready()->create([
                'virtual_machine_id' => $vmInBadState->id,
                'user_id' => $this->customer->id,
                'name' => 'snap-ready',
            ]);

            try {
                $this->snapshotService->restore($readySnapOnBadVm);
                $this->fail(sprintf('Expected InvalidVmStateException for VM state %s on restore.', $invalidVmState));
            } catch (InvalidVmStateException $e) {
                $this->assertInstanceOf(DomainException::class, $e);
            }
        }

        // Restoring a non-ready snapshot must fail with InvalidSnapshotStateException across all non-ready states
        foreach ([VmSnapshot::STATUS_CREATING, VmSnapshot::STATUS_RESTORING, VmSnapshot::STATUS_DELETING, VmSnapshot::STATUS_FAILED] as $badStatus) {
            $nonReadySnapshot = VmSnapshot::factory()->create([
                'virtual_machine_id' => $this->vm->id,
                'user_id' => $this->customer->id,
                'name' => "snap-bad-status-{$badStatus}",
                'status' => $badStatus,
            ]);

            try {
                $this->snapshotService->restore($nonReadySnapshot);
                $this->fail(sprintf('Expected InvalidSnapshotStateException when restoring snapshot with status "%s".', $badStatus));
            } catch (InvalidSnapshotStateException $e) {
                $this->assertInstanceOf(DomainException::class, $e);
                $this->assertSame($badStatus, $e->status);
            }
        }

        // Simultaneous operations on same VM throw InvalidVmStateException
        $busySnapshot = VmSnapshot::factory()->create([
            'virtual_machine_id' => $this->vm->id,
            'user_id' => $this->customer->id,
            'name' => 'in-flight-restoring',
            'status' => VmSnapshot::STATUS_RESTORING,
        ]);

        $readySnapshot = VmSnapshot::factory()->ready()->create([
            'virtual_machine_id' => $this->vm->id,
            'user_id' => $this->customer->id,
            'name' => 'ready-waiting',
        ]);

        // Attempting to restore readySnapshot while busySnapshot is restoring
        try {
            $this->snapshotService->restore($readySnapshot);
            $this->fail('Expected InvalidVmStateException when restoring while another snapshot is restoring.');
        } catch (InvalidVmStateException $e) {
            $this->assertInstanceOf(DomainException::class, $e);
            $this->assertStringContainsString('another snapshot operation', $e->getMessage());
        }

        // Attempting to create snapshot while busySnapshot is restoring
        try {
            $this->snapshotService->create($this->vm, 'new-concurrent-snap');
            $this->fail('Expected InvalidVmStateException when creating snapshot while another snapshot is restoring.');
        } catch (InvalidVmStateException $e) {
            $this->assertInstanceOf(DomainException::class, $e);
            $this->assertStringContainsString('currently restoring', $e->getMessage());
        }

        // Attempting to delete a snapshot while currently restoring
        try {
            $this->snapshotService->delete($busySnapshot);
            $this->fail('Expected InvalidSnapshotStateException when deleting snapshot currently restoring.');
        } catch (InvalidSnapshotStateException $e) {
            $this->assertInstanceOf(DomainException::class, $e);
            $this->assertStringContainsString('currently restoring', $e->getMessage());
        }
    }

    public function test_jobs_record_failed_status_and_error_message_on_failure(): void
    {
        Event::fake([SnapshotStatusUpdated::class]);
        Queue::fake();

        // 1. CreateSnapshotJob failure
        $snapCreate = $this->snapshotService->create($this->vm, 'fail-create');
        $this->fakeDriver->setFailAction('createSnapshot', true);

        try {
            (new CreateSnapshotJob($snapCreate))->handle(app(BackupTargetInterface::class));
            $this->fail('Expected RuntimeException on CreateSnapshotJob failure.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('fail-create', $e->getMessage());
        }

        $snapCreate->refresh();
        $this->assertTrue($snapCreate->status->equals(SnapshotFailed::class));
        $this->assertNotNull($snapCreate->error);
        $this->assertStringContainsString('fail-create', $snapCreate->error);
        Event::assertDispatched(SnapshotStatusUpdated::class, fn (SnapshotStatusUpdated $e): bool => $e->snapshot->id === $snapCreate->id && $e->status === VmSnapshot::STATUS_FAILED);
        Event::assertNotDispatched(SnapshotStatusUpdated::class, fn (SnapshotStatusUpdated $e): bool => $e->snapshot->id === $snapCreate->id && $e->status === VmSnapshot::STATUS_READY);

        // 2. RestoreSnapshotJob failure
        $this->fakeDriver->setFailAction('createSnapshot', false);
        $this->fakeDriver->createSnapshot($this->vm, 'fail-restore');
        $snapRestore = VmSnapshot::factory()->ready()->create([
            'virtual_machine_id' => $this->vm->id,
            'user_id' => $this->customer->id,
            'name' => 'fail-restore',
        ]);
        $this->snapshotService->restore($snapRestore);
        $this->fakeDriver->setFailAction('restoreSnapshot', true);

        try {
            (new RestoreSnapshotJob($snapRestore))->handle(app(BackupTargetInterface::class));
            $this->fail('Expected RuntimeException on RestoreSnapshotJob failure.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('fail-restore', $e->getMessage());
        }

        $snapRestore->refresh();
        $this->assertTrue($snapRestore->status->equals(SnapshotFailed::class));
        $this->assertNotNull($snapRestore->error);
        Event::assertDispatched(SnapshotStatusUpdated::class, fn (SnapshotStatusUpdated $e): bool => $e->snapshot->id === $snapRestore->id && $e->status === VmSnapshot::STATUS_FAILED);
        Event::assertNotDispatched(SnapshotStatusUpdated::class, fn (SnapshotStatusUpdated $e): bool => $e->snapshot->id === $snapRestore->id && $e->status === VmSnapshot::STATUS_READY);

        // 3. DeleteSnapshotJob failure
        $this->fakeDriver->createSnapshot($this->vm, 'fail-delete');
        $snapDelete = VmSnapshot::factory()->ready()->create([
            'virtual_machine_id' => $this->vm->id,
            'user_id' => $this->customer->id,
            'name' => 'fail-delete',
        ]);
        $this->snapshotService->delete($snapDelete);
        $this->fakeDriver->setFailAction('deleteSnapshot', true);

        try {
            (new DeleteSnapshotJob($snapDelete))->handle(app(BackupTargetInterface::class));
            $this->fail('Expected RuntimeException on DeleteSnapshotJob failure.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('fail-delete', $e->getMessage());
        }

        $snapDelete->refresh();
        $this->assertTrue($snapDelete->status->equals(SnapshotFailed::class));
        $this->assertNotNull($snapDelete->error);
        $this->assertDatabaseHas('vm_snapshots', ['id' => $snapDelete->id, 'status' => VmSnapshot::STATUS_FAILED]);
        Event::assertDispatched(SnapshotStatusUpdated::class, fn (SnapshotStatusUpdated $e): bool => $e->snapshot->id === $snapDelete->id && $e->status === VmSnapshot::STATUS_FAILED);
        Event::assertNotDispatched(SnapshotStatusUpdated::class, fn (SnapshotStatusUpdated $e): bool => $e->snapshot->id === $snapDelete->id && $e->status === 'deleted');
    }

    public function test_jobs_are_idempotent_and_do_not_run_concurrently_for_the_same_vm(): void
    {
        Event::fake([SnapshotStatusUpdated::class]);
        Queue::fake();

        $mockTarget = Mockery::mock(BackupTargetInterface::class);
        $mockTarget->shouldReceive('create')
            ->once()
            ->with(Mockery::on(fn (VirtualMachine $v): bool => $v->id === $this->vm->id), 'idem-snap')
            ->andReturn(true);
        $mockTarget->shouldReceive('restore')
            ->once()
            ->with(Mockery::on(fn (VirtualMachine $v): bool => $v->id === $this->vm->id), 'idem-snap')
            ->andReturn(true);
        $mockTarget->shouldReceive('delete')
            ->once()
            ->with(Mockery::on(fn (VirtualMachine $v): bool => $v->id === $this->vm->id), 'idem-snap')
            ->andReturn(true);

        $this->app->instance(BackupTargetInterface::class, $mockTarget);

        // 1. CreateSnapshotJob idempotency (running twice calls target->create only once)
        $snapshot = $this->snapshotService->create($this->vm, 'idem-snap');
        $createJob = new CreateSnapshotJob($snapshot);
        $createJob->handle($mockTarget);
        $createJob->handle($mockTarget);

        Event::assertDispatched(SnapshotStatusUpdated::class, fn (SnapshotStatusUpdated $e): bool => $e->snapshot->id === $snapshot->id && $e->status === VmSnapshot::STATUS_READY);

        // 2. RestoreSnapshotJob idempotency (running twice calls target->restore only once)
        $this->snapshotService->restore($snapshot);
        $restoreJob = new RestoreSnapshotJob($snapshot);
        $restoreJob->handle($mockTarget);
        $restoreJob->handle($mockTarget);

        Event::assertDispatched(SnapshotStatusUpdated::class, fn (SnapshotStatusUpdated $e): bool => $e->snapshot->id === $snapshot->id && $e->status === VmSnapshot::STATUS_READY);

        // 3. DeleteSnapshotJob idempotency (running twice calls target->delete only once)
        $this->snapshotService->delete($snapshot);
        $deleteJob = new DeleteSnapshotJob($snapshot);
        $deleteJob->handle($mockTarget);
        $deleteJob->handle($mockTarget);

        Event::assertDispatched(SnapshotStatusUpdated::class, fn (SnapshotStatusUpdated $e): bool => $e->snapshot->id === $snapshot->id && $e->status === 'deleted');

        // 4. Verify shared WithoutOverlapping middleware on all three jobs for the same VM
        foreach ([$createJob, $restoreJob, $deleteJob] as $job) {
            $middleware = $job->middleware();
            $this->assertNotEmpty($middleware);
            $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
            $this->assertSame("laravel-queue-overlap:vm:{$this->vm->id}", $middleware[0]->getLockKey($job));
        }

        // 5. Verify cache lock prevents concurrent execution on the same VM
        $lockedSnap = VmSnapshot::factory()->creating()->create([
            'virtual_machine_id' => $this->vm->id,
            'user_id' => $this->customer->id,
            'name' => 'locked-snap',
        ]);

        $vmLock = Cache::lock("vm:{$this->vm->id}", 30);
        $this->assertTrue($vmLock->get());

        try {
            $concurrentJob = new CreateSnapshotJob($lockedSnap);
            $concurrentJob->handle($mockTarget);

            // While lock is held, the job must not execute or transition status to ready
            $this->assertTrue($lockedSnap->fresh()?->status->equals(SnapshotCreating::class) ?? false);
        } finally {
            $vmLock->release();
        }
    }

    public function test_deleting_a_failed_snapshot_via_service_and_job_tolerates_backup_target_failure(): void
    {
        Event::fake([SnapshotStatusUpdated::class]);
        Queue::fake();

        $failedSnap = VmSnapshot::factory()->failed('Creation failed earlier')->create([
            'virtual_machine_id' => $this->vm->id,
            'user_id' => $this->customer->id,
            'name' => 'failed-snap-via-service',
        ]);

        $deletingSnap = $this->snapshotService->delete($failedSnap);

        $this->assertTrue($deletingSnap->status->equals(SnapshotDeleting::class));
        $this->assertNull($deletingSnap->error);

        $pushedJob = null;
        Queue::assertPushed(DeleteSnapshotJob::class, function (DeleteSnapshotJob $job) use ($failedSnap, &$pushedJob): bool {
            if ($job->snapshot->is($failedSnap) && $job->wasFailed === true) {
                $pushedJob = $job;

                return true;
            }

            return false;
        });

        $this->assertInstanceOf(DeleteSnapshotJob::class, $pushedJob);

        Event::assertDispatched(SnapshotStatusUpdated::class, fn (SnapshotStatusUpdated $e): bool => $e->snapshot->is($failedSnap) && $e->status === VmSnapshot::STATUS_DELETING);

        // Execute the dispatched job against a backup target that returns false / throws
        $mockTarget = Mockery::mock(BackupTargetInterface::class);
        $mockTarget->shouldReceive('delete')
            ->once()
            ->with(Mockery::on(fn (VirtualMachine $v): bool => $v->id === $this->vm->id), 'failed-snap-via-service')
            ->andThrow(new RuntimeException('Snapshot does not exist on hypervisor'));

        $pushedJob->handle($mockTarget);

        $this->assertDatabaseMissing('vm_snapshots', ['id' => $failedSnap->id]);
        Event::assertDispatched(SnapshotStatusUpdated::class, fn (SnapshotStatusUpdated $e): bool => $e->snapshot->id === $failedSnap->id && $e->status === 'deleted');
    }

    public function test_sync_from_hypervisor_and_reconciliation_service_reconcile_external_snapshots(): void
    {
        // Stale row in DB that no longer exists on hypervisor
        $staleSnap = VmSnapshot::factory()->ready()->create([
            'virtual_machine_id' => $this->vm->id,
            'user_id' => $this->customer->id,
            'name' => 'deleted-externally',
        ]);

        // In-flight creating snapshot not yet present on hypervisor (must be preserved during sync)
        $inFlightSnap = VmSnapshot::factory()->creating()->create([
            'virtual_machine_id' => $this->vm->id,
            'user_id' => $this->customer->id,
            'name' => 'in-flight-creating',
        ]);

        // Snapshot present in both DB and hypervisor
        $keptSnap = VmSnapshot::factory()->creating()->create([
            'virtual_machine_id' => $this->vm->id,
            'user_id' => $this->customer->id,
            'name' => 'kept-snap',
        ]);
        $this->fakeDriver->createSnapshot($this->vm, 'kept-snap');

        // Snapshot created externally on hypervisor (not in DB yet)
        $this->fakeDriver->createSnapshot($this->vm, 'created-externally');

        $this->fakeDriver->projects = ['tenant-snap-svc'];
        $this->fakeDriver->instances = [
            [
                'name' => $this->vm->name,
                'project' => $this->vm->incus_project,
                'status' => 'Stopped',
            ],
        ];
        $this->fakeDriver->statuses[$this->vm->name] = 'Stopped';

        // Run snapshot sync via dedicated artisan command (which invokes VmSnapshotService -> syncFromHypervisor)
        $this->artisan('snapshots:sync', ['host' => $this->host->id])
            ->assertExitCode(0);

        // Stale snapshot was removed
        $this->assertDatabaseMissing('vm_snapshots', ['id' => $staleSnap->id]);

        // In-flight creating snapshot was preserved
        $this->assertDatabaseHas('vm_snapshots', [
            'id' => $inFlightSnap->id,
            'name' => 'in-flight-creating',
            'status' => VmSnapshot::STATUS_CREATING,
        ]);

        // Existing snapshot was synced to ready
        $this->assertDatabaseHas('vm_snapshots', [
            'id' => $keptSnap->id,
            'name' => 'kept-snap',
            'status' => VmSnapshot::STATUS_READY,
        ]);

        // Externally created snapshot was imported as ready
        $this->assertDatabaseHas('vm_snapshots', [
            'virtual_machine_id' => $this->vm->id,
            'user_id' => $this->customer->id,
            'name' => 'created-externally',
            'status' => VmSnapshot::STATUS_READY,
        ]);

        // Also test direct syncFromHypervisor when external snapshot is deleted on hypervisor
        $inFlightSnap->delete();
        $this->fakeDriver->deleteSnapshot($this->vm, 'created-externally');
        $synced = app(VmSnapshotService::class)->syncFromHypervisor($this->vm);

        $this->assertCount(1, $synced);
        $this->assertSame('kept-snap', $synced->first()?->name);
        $this->assertDatabaseMissing('vm_snapshots', [
            'virtual_machine_id' => $this->vm->id,
            'name' => 'created-externally',
        ]);
    }
}
