<?php

declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditAction;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Audit\AuditOutcome;
use App\Contracts\BackupTargetInterface;
use App\Events\SnapshotStatusUpdated;
use App\Events\Storage\SnapshotCreated;
use App\Exceptions\DuplicateSnapshotNameException;
use App\Exceptions\InvalidSnapshotNameException;
use App\Exceptions\InvalidSnapshotStateException;
use App\Exceptions\InvalidVmStateException;
use App\Exceptions\SnapshotQuotaExceededException;
use App\Jobs\CreateSnapshotJob;
use App\Jobs\DeleteSnapshotJob;
use App\Jobs\RestoreSnapshotJob;
use App\Models\VirtualMachine;
use App\Models\VmSnapshot;
use App\States\Host\Unreachable;
use App\States\Snapshot\Creating;
use App\States\Snapshot\Deleting;
use App\States\Snapshot\Failed;
use App\States\Snapshot\Ready;
use App\States\Snapshot\Restoring;
use App\States\Vm\Running;
use App\States\Vm\Stopped;
use App\States\Vm\Suspended;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Service orchestrating virtual machine snapshot creation, restoration, deletion,
 * and hypervisor synchronization through the BackupTargetInterface abstraction.
 *
 * Restore VM State Policy:
 * - A snapshot can only be restored when the snapshot itself is in `ready` status.
 * - The target VM must be in a stable, provisioned lifecycle state (`running`, `stopped`, or `suspended`)
 *   and must not have another snapshot operation (`creating`, `restoring`, `deleting`) currently in progress.
 * - Attempting to create or restore a snapshot while the VM is in `provisioning`, `deleting`, `deleted`,
 *   or `failed` state throws `InvalidVmStateException` (a `DomainException`).
 * - During restoration, the snapshot is marked `restoring` and `RestoreSnapshotJob` delegates to the
 *   configured `BackupTargetInterface`, which restores the instance state on the hypervisor before returning
 *   the snapshot status to `ready`.
 */
class VmSnapshotService
{
    private readonly UserQuotaService $quotaService;

    public function __construct(
        private readonly BackupTargetInterface $backupTarget,
        ?UserQuotaService $quotaService = null,
        private ?AuditLogger $auditLogger = null,
    ) {
        $this->quotaService = $quotaService ?? app(UserQuotaService::class);
        $this->auditLogger ??= app(AuditLogger::class);
    }

    private function logger(): AuditLogger
    {
        return $this->auditLogger ??= app(AuditLogger::class);
    }

    /**
     * Validate quota, VM state, and snapshot name (slug-safe, unique per VM),
     * insert a snapshot record with `creating` status, and dispatch `CreateSnapshotJob`.
     *
     * @param  string|array{name?: string, source?: string}  $name
     *
     * @throws InvalidVmStateException
     * @throws InvalidSnapshotStateException
     * @throws InvalidSnapshotNameException
     * @throws DuplicateSnapshotNameException
     * @throws SnapshotQuotaExceededException
     * @throws ValidationException
     */
    public function create(
        VirtualMachine $vm,
        string|array $name,
        string $source = VmSnapshot::SOURCE_MANUAL,
    ): VmSnapshot {
        if (is_array($name)) {
            $snapshotName = isset($name['name']) ? (string) $name['name'] : '';
            $source = isset($name['source']) ? (string) $name['source'] : $source;
        } else {
            $snapshotName = $name;
        }

        try {
            $this->assertValidVmStateForCreate($vm);
            $this->assertValidSnapshotName($snapshotName);
            $this->assertValidSource($source);
            $this->assertHostReachable($vm);

            $lock = Cache::lock(sprintf('vm_snapshot_create:%d', $vm->id), 10);

            /** @var VmSnapshot $snapshot */
            $snapshot = $lock->block(5, function () use ($vm, $snapshotName, $source): VmSnapshot {
                return DB::transaction(function () use ($vm, $snapshotName, $source): VmSnapshot {
                    $this->assertValidVmStateForCreate($vm);

                    if (VmSnapshot::query()
                        ->where('virtual_machine_id', $vm->id)
                        ->where('name', $snapshotName)
                        ->exists()
                    ) {
                        throw new DuplicateSnapshotNameException($snapshotName);
                    }

                    if (! $this->quotaService->canCreateSnapshot($vm)) {
                        /** @var string $message */
                        $message = __('messages.quota.snapshots_exceeded');
                        throw new SnapshotQuotaExceededException($message);
                    }

                    /** @var VmSnapshot $createdSnapshot */
                    $createdSnapshot = VmSnapshot::create([
                        'virtual_machine_id' => $vm->id,
                        'user_id' => $vm->user_id,
                        'name' => $snapshotName,
                        'source' => $source,
                        'status' => Creating::class,
                        'size_bytes' => null,
                        'error' => null,
                    ]);

                    $this->logger()->record(new AuditEntry(
                        action: AuditAction::VmSnapshotCreated,
                        outcome: AuditOutcome::Success,
                        subject: $createdSnapshot,
                        tenantUserId: $vm->user_id,
                        visibility: 'tenant',
                        context: [
                            'name' => $createdSnapshot->name,
                            'virtual_machine_id' => $vm->id,
                        ],
                    ));

                    event(new SnapshotCreated($createdSnapshot->id, $vm->id, $createdSnapshot->name));

                    return $createdSnapshot;
                });
            });
        } catch (\Throwable $e) {
            $this->logger()->recordIndependent(new AuditEntry(
                action: AuditAction::VmActionDenied,
                outcome: AuditOutcome::Denied,
                subject: $vm,
                tenantUserId: $vm->user_id,
                visibility: 'tenant',
                context: [
                    'action' => 'snapshot.create',
                    'name' => $snapshotName,
                    'virtual_machine_id' => $vm->id,
                    'reason' => $e->getMessage(),
                ],
            ));

            throw $e;
        }

        event(new SnapshotStatusUpdated($snapshot));

        CreateSnapshotJob::dispatch($snapshot);

        return $snapshot;
    }

    /**
     * Restore a `ready` snapshot on its virtual machine.
     *
     * Marks the snapshot as `restoring` and dispatches `RestoreSnapshotJob`.
     * Accepts either `(VmSnapshot $snapshot)` or `(VirtualMachine $vm, VmSnapshot|string|int $snapshot)`.
     *
     * @throws InvalidSnapshotStateException
     * @throws InvalidVmStateException
     * @throws ValidationException
     */
    public function restore(
        VmSnapshot|VirtualMachine $snapshotOrVm,
        VmSnapshot|string|int|null $snapshot = null,
    ): VmSnapshot {
        $resolvedSnapshot = null;

        try {
            [$vm, $resolvedSnapshot] = $this->resolveVmAndSnapshot($snapshotOrVm, $snapshot);

            /** @var VmSnapshot $targetSnapshot */
            $targetSnapshot = DB::transaction(function () use ($vm, $resolvedSnapshot): VmSnapshot {
                /** @var VmSnapshot $lockedSnapshot */
                $lockedSnapshot = VmSnapshot::query()
                    ->whereKey($resolvedSnapshot->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $lockedSnapshot->status->equals(Ready::class)) {
                    throw new InvalidSnapshotStateException(
                        $lockedSnapshot->status->getValue(),
                        'restore',
                        sprintf('Cannot restore snapshot "%s" while in "%s" status. Only "ready" snapshots can be restored.', $lockedSnapshot->name, $lockedSnapshot->status->getValue())
                    );
                }

                $this->assertValidVmStateForRestore($vm, $lockedSnapshot);
                $this->assertHostReachable($vm);

                $lockedSnapshot->error = null;
                $lockedSnapshot->status->transitionTo(Restoring::class);

                $this->logger()->record(new AuditEntry(
                    action: AuditAction::VmSnapshotRestored,
                    outcome: AuditOutcome::Success,
                    subject: $lockedSnapshot,
                    tenantUserId: $vm->user_id,
                    visibility: 'tenant',
                    context: [
                        'name' => $lockedSnapshot->name,
                        'virtual_machine_id' => $vm->id,
                        'previous_state' => (string) $vm->status,
                    ],
                ));

                return $lockedSnapshot;
            });
        } catch (\Throwable $e) {
            $subjectVm = $snapshotOrVm instanceof VirtualMachine ? $snapshotOrVm : $snapshotOrVm->virtualMachine;
            $this->logger()->recordIndependent(new AuditEntry(
                action: AuditAction::VmActionDenied,
                outcome: AuditOutcome::Denied,
                subject: $subjectVm,
                tenantUserId: $subjectVm->user_id,
                visibility: 'tenant',
                context: [
                    'action' => 'snapshot.restore',
                    'name' => $resolvedSnapshot !== null ? $resolvedSnapshot->name : (is_string($snapshot) ? $snapshot : null),
                    'virtual_machine_id' => $subjectVm->id,
                    'reason' => $e->getMessage(),
                ],
            ));

            throw $e;
        }

        if ($snapshotOrVm instanceof VmSnapshot || $snapshot instanceof VmSnapshot) {
            $resolvedSnapshot->refresh();
        }

        RestoreSnapshotJob::dispatch($targetSnapshot);

        return $targetSnapshot;
    }

    /**
     * Mark a snapshot as `deleting` and dispatch `DeleteSnapshotJob`.
     *
     * Accepts either `(VmSnapshot $snapshot)` or `(VirtualMachine $vm, VmSnapshot|string|int $snapshot)`.
     *
     * @throws InvalidSnapshotStateException
     */
    public function delete(
        VmSnapshot|VirtualMachine $snapshotOrVm,
        VmSnapshot|string|int|null $snapshot = null,
    ): VmSnapshot {
        $resolvedSnapshot = null;
        $wasFailed = false;

        try {
            [, $resolvedSnapshot] = $this->resolveVmAndSnapshot($snapshotOrVm, $snapshot);

            /** @var VmSnapshot $targetSnapshot */
            $targetSnapshot = DB::transaction(function () use ($resolvedSnapshot, &$wasFailed): VmSnapshot {
                /** @var VmSnapshot $lockedSnapshot */
                $lockedSnapshot = VmSnapshot::query()
                    ->whereKey($resolvedSnapshot->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedSnapshot->status->equals(Restoring::class)) {
                    throw new InvalidSnapshotStateException(
                        $lockedSnapshot->status->getValue(),
                        'delete',
                        sprintf('Cannot delete snapshot "%s" while it is currently restoring.', $lockedSnapshot->name)
                    );
                }

                if (! $lockedSnapshot->status->equals(Ready::class, Failed::class)) {
                    throw new InvalidSnapshotStateException(
                        $lockedSnapshot->status->getValue(),
                        'delete',
                        sprintf('Cannot delete snapshot "%s" while in "%s" status.', $lockedSnapshot->name, $lockedSnapshot->status->getValue())
                    );
                }

                $wasFailed = $lockedSnapshot->status->equals(Failed::class);

                $lockedSnapshot->error = null;
                $lockedSnapshot->status->transitionTo(Deleting::class);

                $this->logger()->record(new AuditEntry(
                    action: AuditAction::VmSnapshotDeleted,
                    outcome: AuditOutcome::Success,
                    subject: $lockedSnapshot,
                    tenantUserId: $lockedSnapshot->user_id,
                    visibility: 'tenant',
                    context: [
                        'name' => $lockedSnapshot->name,
                        'virtual_machine_id' => $lockedSnapshot->virtual_machine_id,
                    ],
                ));

                return $lockedSnapshot;
            });
        } catch (\Throwable $e) {
            /** @var VirtualMachine|null $subjectVm */
            $subjectVm = $snapshotOrVm instanceof VirtualMachine ? $snapshotOrVm : $snapshotOrVm->virtualMachine;
            $this->logger()->recordIndependent(new AuditEntry(
                action: AuditAction::VmActionDenied,
                outcome: AuditOutcome::Denied,
                subject: $subjectVm,
                tenantUserId: $subjectVm !== null ? $subjectVm->user_id : null,
                visibility: 'tenant',
                context: [
                    'action' => 'snapshot.delete',
                    'name' => $resolvedSnapshot?->name,
                    'virtual_machine_id' => $resolvedSnapshot !== null ? $resolvedSnapshot->virtual_machine_id : ($subjectVm !== null ? $subjectVm->id : null),
                    'reason' => $e->getMessage(),
                ],
            ));

            throw $e;
        }

        if ($snapshotOrVm instanceof VmSnapshot || $snapshot instanceof VmSnapshot) {
            $resolvedSnapshot->refresh();
        }

        DeleteSnapshotJob::dispatch($targetSnapshot, wasFailed: $wasFailed);

        return $targetSnapshot;
    }

    /**
     * Reconcile database snapshot rows for the given VM with the live snapshots reported by the BackupTarget.
     *
     * - Externally created snapshots on the hypervisor are inserted as `ready`.
     * - Existing snapshots present on the hypervisor have their metadata (`size_bytes`, `status=ready`) updated.
     * - Stale database rows no longer present on the hypervisor are removed.
     *
     * @return Collection<int, VmSnapshot>
     */
    public function syncFromHypervisor(VirtualMachine $vm): Collection
    {
        $liveSnapshots = $this->backupTarget->list($vm);

        /** @var array<string, array{name: string, created_at: string, size: int|null}> $liveByName */
        $liveByName = [];
        foreach ($liveSnapshots as $item) {
            $name = trim($item['name']);
            if ($name === '') {
                continue;
            }
            $liveByName[$name] = [
                'name' => $name,
                'created_at' => $item['created_at'],
                'size' => $item['size'],
            ];
        }

        DB::transaction(function () use ($vm, $liveByName): void {
            /** @var Collection<int, VmSnapshot> $dbSnapshots */
            $dbSnapshots = $vm->snapshots()->get();
            $seenNames = [];

            foreach ($dbSnapshots as $dbSnapshot) {
                $name = $dbSnapshot->name;

                if (isset($liveByName[$name])) {
                    $seenNames[$name] = true;
                    $live = $liveByName[$name];

                    if ($live['size'] !== null) {
                        $dbSnapshot->size_bytes = $live['size'];
                    }
                    if ($dbSnapshot->status->equals(Creating::class)) {
                        $dbSnapshot->error = null;
                        $dbSnapshot->status->transitionTo(Ready::class);
                    } elseif ($dbSnapshot->status->equals(Ready::class)) {
                        $dbSnapshot->error = null;
                        if ($dbSnapshot->isDirty()) {
                            $dbSnapshot->save();
                        }
                    } elseif ($dbSnapshot->isDirty()) {
                        $dbSnapshot->save();
                    }
                } elseif (! $dbSnapshot->status->equals(Creating::class, Deleting::class, Failed::class)) {
                    // Snapshot no longer exists on hypervisor -> remove stale DB row (preserve in-flight creating/deleting jobs and failed records)
                    $dbSnapshot->delete();
                }
            }

            // Insert snapshots that exist on the hypervisor but not in the database
            foreach ($liveByName as $name => $live) {
                if (isset($seenNames[$name])) {
                    continue;
                }

                VmSnapshot::create([
                    'virtual_machine_id' => $vm->id,
                    'user_id' => $vm->user_id,
                    'name' => $name,
                    'source' => VmSnapshot::SOURCE_MANUAL,
                    'status' => Ready::class,
                    'size_bytes' => $live['size'],
                    'error' => null,
                ]);
            }
        });

        return $vm->snapshots()->orderBy('id')->get();
    }

    /**
     * Validate that the compute node hosting the VM is reachable.
     *
     * @throws ValidationException
     */
    private function assertHostReachable(VirtualMachine $vm): void
    {
        if ($vm->relationLoaded('host')) {
            $host = $vm->host;
        } else {
            $host = $vm->host()->first();
        }

        if ($host !== null && $host->status->equals(Unreachable::class)) {
            $exception = ValidationException::withMessages([
                'host' => ['The compute node hosting this virtual machine is currently unreachable.'],
            ]);
            $exception->errorBag = 'default';
            $exception->response = response()->json([
                'message' => 'Host Unreachable',
                'errors' => [
                    'host' => ['The compute node hosting this virtual machine is currently unreachable.'],
                ],
            ], 422);
            throw $exception;
        }
    }

    /**
     * Validate that the VM is in a state that permits creating a snapshot.
     *
     * @throws InvalidVmStateException
     */
    private function assertValidVmStateForCreate(VirtualMachine $vm): void
    {
        if (! $this->isVmInStableState($vm)) {
            throw new InvalidVmStateException(
                (string) $vm->status,
                'create snapshot',
                sprintf(
                    'Cannot create snapshot while virtual machine "%s" is in "%s" state. VM must be running, stopped, or suspended.',
                    $vm->name,
                    (string) $vm->status
                )
            );
        }

        $hasRestoring = VmSnapshot::query()
            ->where('virtual_machine_id', $vm->id)
            ->where('status', VmSnapshot::STATUS_RESTORING)
            ->exists();

        if ($hasRestoring) {
            throw new InvalidVmStateException(
                'restoring',
                'create snapshot',
                sprintf('Cannot create snapshot while virtual machine "%s" is currently restoring a snapshot.', $vm->name)
            );
        }
    }

    /**
     * Validate that the VM is in a state that permits restoring a snapshot.
     *
     * @throws InvalidVmStateException
     */
    private function assertValidVmStateForRestore(VirtualMachine $vm, VmSnapshot $snapshot): void
    {
        if (! $this->isVmInStableState($vm)) {
            throw new InvalidVmStateException(
                (string) $vm->status,
                'restore snapshot',
                sprintf(
                    'Cannot restore snapshot while virtual machine "%s" is in "%s" state. VM must be running, stopped, or suspended.',
                    $vm->name,
                    (string) $vm->status
                )
            );
        }

        $hasBusySnapshot = VmSnapshot::query()
            ->where('virtual_machine_id', $vm->id)
            ->where('id', '!=', $snapshot->id)
            ->whereIn('status', [
                VmSnapshot::STATUS_CREATING,
                VmSnapshot::STATUS_RESTORING,
                VmSnapshot::STATUS_DELETING,
            ])
            ->exists();

        if ($hasBusySnapshot) {
            throw new InvalidVmStateException(
                'busy',
                'restore snapshot',
                sprintf('Cannot restore snapshot while virtual machine "%s" has another snapshot operation in progress.', $vm->name)
            );
        }
    }

    /**
     * Check whether the virtual machine is in a stable provisioned state (`running`, `stopped`, or `suspended`).
     */
    private function isVmInStableState(VirtualMachine $vm): bool
    {
        return $vm->status->equals(Running::class)
            || $vm->status->equals(Stopped::class)
            || $vm->status->equals(Suspended::class);
    }

    /**
     * Validate that the snapshot name is a non-empty, slug-safe identifier.
     *
     * @throws InvalidSnapshotNameException
     */
    private function assertValidSnapshotName(string $name): void
    {
        if ($name === '' || preg_match('/^[a-z0-9](?:[a-z0-9_-]{0,61}[a-z0-9])?$/', $name) !== 1) {
            throw new InvalidSnapshotNameException($name);
        }
    }

    /**
     * Validate snapshot source value.
     *
     * @throws InvalidSnapshotNameException
     */
    private function assertValidSource(string $source): void
    {
        if (! in_array($source, [VmSnapshot::SOURCE_MANUAL, VmSnapshot::SOURCE_SCHEDULED], true)) {
            throw new InvalidSnapshotNameException($source, sprintf('Invalid snapshot source "%s".', $source));
        }
    }

    /**
     * Resolve `[VirtualMachine, VmSnapshot]` from flexible argument combinations.
     *
     * @return array{0: VirtualMachine, 1: VmSnapshot}
     */
    private function resolveVmAndSnapshot(
        VmSnapshot|VirtualMachine $snapshotOrVm,
        VmSnapshot|string|int|null $snapshot = null,
    ): array {
        if ($snapshotOrVm instanceof VmSnapshot) {
            /** @var VirtualMachine $vm */
            $vm = $snapshotOrVm->virtualMachine ?? VirtualMachine::findOrFail($snapshotOrVm->virtual_machine_id);

            return [$vm, $snapshotOrVm];
        }

        $vm = $snapshotOrVm;

        if ($snapshot instanceof VmSnapshot) {
            if ($snapshot->virtual_machine_id !== $vm->id) {
                throw new InvalidSnapshotStateException(
                    $snapshot->status->getValue(),
                    'operate on',
                    sprintf('Snapshot "%s" does not belong to virtual machine #%d.', $snapshot->name, $vm->id)
                );
            }

            return [$vm, $snapshot];
        }

        if (is_int($snapshot)) {
            /** @var VmSnapshot $found */
            $found = $vm->snapshots()->whereKey($snapshot)->firstOrFail();

            return [$vm, $found];
        }

        if (is_string($snapshot) && $snapshot !== '') {
            /** @var VmSnapshot $found */
            $found = $vm->snapshots()->where('name', $snapshot)->firstOrFail();

            return [$vm, $found];
        }

        throw new InvalidSnapshotStateException('unknown', 'resolve', 'A valid VmSnapshot instance, ID, or name must be provided.');
    }
}
