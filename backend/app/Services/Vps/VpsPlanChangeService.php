<?php

declare(strict_types=1);

namespace App\Services\Vps;

use App\Audit\AuditAction;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Audit\AuditOutcome;
use App\Contracts\Data\VpsAuthorizationInitiator;
use App\Contracts\Data\VpsPlanChangeAuthorizationRequest;
use App\Contracts\Data\VpsPlanChangeAuthorizationResult;
use App\Contracts\VpsPlanChangeAuthorizerInterface;
use App\Enums\Vps\VpsAuthorizationStatus;
use App\Events\Instance\InstanceResized;
use App\Events\Vps\VpsPlanChangeCancelled;
use App\Events\Vps\VpsPlanChangeCompleted;
use App\Events\Vps\VpsPlanChangeFailed;
use App\Events\Vps\VpsPlanChangeReconciliationRequired;
use App\Events\Vps\VpsPlanChangeRequested;
use App\Events\Vps\VpsPlanChangeStarted;
use App\Exceptions\DriverUnavailableException;
use App\Exceptions\NoHypervisorAvailableException;
use App\Jobs\ApplyVpsPlanChangeJob;
use App\Models\DriftReport;
use App\Models\Host;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserQuota;
use App\Models\VirtualMachine;
use App\Models\VpsPlanChange;
use App\Models\VpsPlanVersion;
use App\Services\Hypervisor\HypervisorManager;
use App\States\Vm\Running as VmRunning;
use App\States\Vm\Stopped as VmStopped;
use App\States\VpsPlanChange\Applying;
use App\States\VpsPlanChange\AwaitingAuthorization;
use App\States\VpsPlanChange\Cancelled;
use App\States\VpsPlanChange\Completed;
use App\States\VpsPlanChange\Failed;
use App\States\VpsPlanChange\Pending;
use App\States\VpsPlanChange\ReconciliationRequired;
use App\Support\Cast;
use App\ValueObjects\Hypervisor\InstanceResourceSpec;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Orchestrates VPS plan-change requests, cancellations, state transitions,
 * final VM resource commits, and manual reconciliation resolution.
 */
final class VpsPlanChangeService
{
    private const int BYTES_PER_MIB = 1048576;

    private const int BYTES_PER_GIB = 1073741824;

    public function __construct(
        private readonly VpsPlanChangeValidator $validator = new VpsPlanChangeValidator,
        private readonly HypervisorManager $hypervisorManager = new HypervisorManager,
        private ?AuditLogger $auditLogger = null,
        private readonly ?VpsPlanChangeAuthorizerInterface $authorizer = null,
    ) {
        $this->auditLogger ??= app(AuditLogger::class);
    }

    /**
     * Request a VPS plan change for a virtual machine, authorizing via the pluggable authorizer
     * outside locks, reserving capacity/quota under lock, and dispatching execution or pausing for approval.
     *
     * @throws ValidationException
     */
    public function request(
        VirtualMachine $vm,
        VpsPlanVersion $targetVersion,
        User $actor,
        bool $isAdmin = false,
    ): VpsPlanChange {
        $preValidation = $this->validator->validate($vm, $targetVersion, $isAdmin);
        if (! $preValidation['can_submit']) {
            $this->denyRequest($vm, $targetVersion, $actor, $preValidation['blocking_reasons']);
        }

        $publicId = strtolower((string) Str::ulid());
        $authRequest = new VpsPlanChangeAuthorizationRequest(
            requestId: $publicId,
            userId: (int) $vm->user_id,
            virtualMachineId: (int) $vm->id,
            fromVersionId: $vm->vps_plan_version_id,
            toVersionId: (int) $targetVersion->id,
            oldResources: $this->vmResourceSnapshot($vm),
            newResources: $this->versionResourceSnapshot($targetVersion),
            initiator: new VpsAuthorizationInitiator(
                id: (int) $actor->id,
                isAdmin: $isAdmin,
            ),
        );

        $authResult = $this->authorizePlanChange($authRequest);

        if ($authResult->status === VpsAuthorizationStatus::Denied) {
            $message = ($authResult->safeMessage !== null && trim($authResult->safeMessage) !== '')
                ? trim($authResult->safeMessage)
                : 'Plan change request was denied by the authorization provider.';

            $this->denyRequest($vm, $targetVersion, $actor, [[
                'code' => 'EXTENSION_AUTHORIZATION_DENIED',
                'message' => $message,
                'kind' => 'authorization',
            ]]);
        }

        $isPendingAuthorization = $authResult->status === VpsAuthorizationStatus::Pending;

        $lock = Cache::lock(sprintf('user_quota_lock:%d', $vm->user_id), 10);

        try {
            /** @var VpsPlanChange $change */
            $change = $lock->block(5, function () use ($vm, $targetVersion, $actor, $isAdmin, $publicId, $authResult, $isPendingAuthorization): VpsPlanChange {
                return DB::transaction(function () use ($vm, $targetVersion, $actor, $isAdmin, $publicId, $authResult, $isPendingAuthorization): VpsPlanChange {
                    $quota = UserQuota::firstOrCreate(
                        ['user_id' => $vm->user_id],
                        SystemSetting::getDefaultQuota(),
                    );
                    UserQuota::query()->where('id', $quota->id)->lockForUpdate()->firstOrFail();

                    if ($vm->host_id !== null) {
                        Host::query()->where('id', $vm->host_id)->lockForUpdate()->first();
                    }

                    /** @var VirtualMachine $lockedVm */
                    $lockedVm = VirtualMachine::query()
                        ->with(['host', 'primaryAllocation.pool'])
                        ->where('id', $vm->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    /** @var VpsPlanVersion $lockedVersion */
                    $lockedVersion = VpsPlanVersion::query()
                        ->with('vpsPlan')
                        ->where('id', $targetVersion->id)
                        ->firstOrFail();

                    $validation = $this->validator->validate(
                        vm: $lockedVm,
                        targetVersion: $lockedVersion,
                        isAdmin: $isAdmin,
                        skipDriverAssessment: true,
                        useLocks: true,
                    );
                    if (! $validation['can_submit']) {
                        $this->denyRequest($lockedVm, $lockedVersion, $actor, $validation['blocking_reasons']);
                    }

                    $oldResources = $this->vmResourceSnapshot($lockedVm);
                    $newResources = $this->versionResourceSnapshot($lockedVersion);

                    /** @var VpsPlanChange $created */
                    $created = VpsPlanChange::query()->create([
                        'public_id' => $publicId,
                        'virtual_machine_id' => $lockedVm->id,
                        'user_id' => $lockedVm->user_id,
                        'initiated_by' => $actor->id,
                        'from_version_id' => $lockedVm->vps_plan_version_id,
                        'to_version_id' => $lockedVersion->id,
                        'old_resources' => $oldResources,
                        'new_resources' => $newResources,
                        'status' => $isPendingAuthorization ? AwaitingAuthorization::class : Pending::class,
                        'stage' => $isPendingAuthorization ? 'authorization' : null,
                        'authorization_ref' => $authResult->reference,
                        'driver_name' => $lockedVm->host?->driver,
                        'requested_at' => Carbon::now(),
                    ]);

                    $this->logger()->record(new AuditEntry(
                        action: AuditAction::VpsPlanChangeRequested,
                        outcome: AuditOutcome::Success,
                        subject: $created,
                        tenantUserId: $lockedVm->user_id,
                        visibility: 'tenant',
                        context: [
                            'vps_plan_change_id' => $created->id,
                            'public_id' => $created->public_id,
                            'virtual_machine_id' => $lockedVm->id,
                            'from_version_id' => $created->from_version_id,
                            'to_version_id' => $created->to_version_id,
                            'old_resources' => $oldResources,
                            'new_resources' => $newResources,
                            'authorization_ref' => $authResult->reference,
                        ],
                        actorType: 'user',
                        actorId: $actor->id,
                        actorLabel: $this->actorLabel($actor),
                    ));

                    VpsPlanChangeRequested::dispatch(
                        $created->id,
                        $created->public_id,
                        $created->virtual_machine_id,
                        $created->user_id,
                        $created->from_version_id,
                        $created->to_version_id,
                        $oldResources,
                        $newResources,
                        $this->eventActor($actor),
                    );

                    if (! $isPendingAuthorization) {
                        ApplyVpsPlanChangeJob::dispatch($created->id, $created->virtual_machine_id)->afterCommit();
                    }

                    return $created->fresh() ?? $created;
                });
            });

            return $change;
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages([
                'quota' => ['Another resource request is currently being processed for this account. Please retry in a moment.'],
            ]);
        }
    }

    /**
     * Resume an awaiting-authorization VPS plan change using a locked CAS check.
     */
    public function resumeAuthorization(string $publicId, VpsPlanChangeAuthorizationResult $result): VpsPlanChange
    {
        return DB::transaction(function () use ($publicId, $result): VpsPlanChange {
            /** @var VpsPlanChange|null $locked */
            $locked = VpsPlanChange::query()
                ->where('public_id', $publicId)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw new NotFoundHttpException('VPS plan change request not found.');
            }

            if (! $locked->status->equals(AwaitingAuthorization::class)) {
                throw new ConflictHttpException('This plan change is not awaiting authorization.');
            }

            if ($result->reference !== null) {
                $locked->authorization_ref = $result->reference;
                $locked->save();
            }

            if ($result->status === VpsAuthorizationStatus::Pending) {
                return $locked->fresh() ?? $locked;
            }

            if ($result->status === VpsAuthorizationStatus::Denied) {
                $errorCode = ($result->reasonCode !== null && trim($result->reasonCode) !== '')
                    ? trim($result->reasonCode)
                    : 'EXTENSION_AUTHORIZATION_DENIED';
                $errorMessage = ($result->safeMessage !== null && trim($result->safeMessage) !== '')
                    ? trim($result->safeMessage)
                    : 'Plan change authorization was denied.';

                return $this->markFailed(
                    change: $locked,
                    errorCode: $errorCode,
                    errorMessage: $errorMessage,
                    stage: 'authorization',
                );
            }

            /** @var VirtualMachine|null $vm */
            $vm = VirtualMachine::query()
                ->where('id', $locked->virtual_machine_id)
                ->lockForUpdate()
                ->first();

            if ($vm === null) {
                return $this->markFailed(
                    change: $locked,
                    errorCode: 'VM_NOT_FOUND',
                    errorMessage: 'Virtual machine no longer exists.',
                    stage: 'authorization',
                );
            }

            if (! ($vm->status->equals(VmRunning::class) || $vm->status->equals(VmStopped::class))) {
                return $this->markFailed(
                    change: $locked,
                    errorCode: 'VM_STATE_NOT_SUPPORTED',
                    errorMessage: 'Virtual machine is not in a supported state (running or stopped) for plan change.',
                    stage: 'authorization',
                );
            }

            $locked->stage = null;
            $locked->status->transitionTo(Pending::class);

            ApplyVpsPlanChangeJob::dispatch($locked->id, $locked->virtual_machine_id)->afterCommit();

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * Cancel a queued or awaiting-authorization VPS plan change using a locked CAS check.
     */
    public function cancel(VpsPlanChange $change, User $actor): VpsPlanChange
    {
        return DB::transaction(function () use ($change, $actor): VpsPlanChange {
            /** @var VpsPlanChange $locked */
            $locked = VpsPlanChange::query()
                ->where('id', $change->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->canCancel()) {
                throw new ConflictHttpException('This plan change can no longer be cancelled.');
            }

            $locked->status->transitionTo(Cancelled::class);

            $this->logger()->record(new AuditEntry(
                action: AuditAction::VpsPlanChangeCancelled,
                outcome: AuditOutcome::Success,
                subject: $locked,
                tenantUserId: $locked->user_id,
                visibility: 'tenant',
                context: [
                    'vps_plan_change_id' => $locked->id,
                    'public_id' => $locked->public_id,
                    'virtual_machine_id' => $locked->virtual_machine_id,
                    'from_version_id' => $locked->from_version_id,
                    'to_version_id' => $locked->to_version_id,
                ],
                actorType: 'user',
                actorId: $actor->id,
                actorLabel: $this->actorLabel($actor),
            ));

            VpsPlanChangeCancelled::dispatch(
                $locked->id,
                $locked->public_id,
                $locked->virtual_machine_id,
                $locked->user_id,
                $locked->from_version_id,
                $locked->to_version_id,
                $this->eventActor($actor),
            );

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * Perform a locked CAS transition from Pending to Applying for job execution.
     * Returns null when the change is no longer in Pending.
     */
    public function startApplying(VpsPlanChange $change): ?VpsPlanChange
    {
        return DB::transaction(function () use ($change): ?VpsPlanChange {
            /** @var VpsPlanChange|null $locked */
            $locked = VpsPlanChange::query()
                ->where('id', $change->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null || ! $locked->status->equals(Pending::class)) {
                return null;
            }

            $deadlineMinutes = max(1, Cast::int(config('vps.apply_deadline_minutes', 20), 20));
            $now = Carbon::now();

            $locked->started_at = $now;
            $locked->apply_deadline = $now->copy()->addMinutes($deadlineMinutes);
            $locked->stage = 'validating';
            $locked->status->transitionTo(Applying::class);

            VpsPlanChangeStarted::dispatch(
                $locked->id,
                $locked->public_id,
                $locked->virtual_machine_id,
                $locked->user_id,
                $locked->from_version_id,
                $locked->to_version_id,
            );

            return $locked->fresh(['virtualMachine.host', 'toVersion']) ?? $locked;
        });
    }

    /**
     * Transition an active plan change to Failed with error metadata, audit log, and event.
     */
    public function markFailed(
        VpsPlanChange $change,
        string $errorCode,
        string $errorMessage,
        ?string $stage = null,
        ?User $actor = null,
    ): VpsPlanChange {
        return DB::transaction(function () use ($change, $errorCode, $errorMessage, $stage, $actor): VpsPlanChange {
            /** @var VpsPlanChange $locked */
            $locked = VpsPlanChange::query()
                ->where('id', $change->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status->equals(Failed::class)) {
                return $locked;
            }

            if (! $locked->status->canTransitionTo(Failed::class)) {
                throw new ConflictHttpException('Plan change cannot transition to failed from its current state.');
            }

            $locked->error_code = $errorCode;
            $locked->error_message = $errorMessage;
            $locked->failed_at = Carbon::now();
            if ($stage !== null) {
                $locked->stage = $stage;
            }

            $locked->status->transitionTo(Failed::class);

            $this->logger()->record(new AuditEntry(
                action: AuditAction::VpsPlanChangeFailed,
                outcome: AuditOutcome::Failed,
                subject: $locked,
                tenantUserId: $locked->user_id,
                visibility: 'tenant',
                context: [
                    'vps_plan_change_id' => $locked->id,
                    'public_id' => $locked->public_id,
                    'virtual_machine_id' => $locked->virtual_machine_id,
                    'from_version_id' => $locked->from_version_id,
                    'to_version_id' => $locked->to_version_id,
                    'error_code' => $errorCode,
                    'error_message' => $errorMessage,
                    'stage' => $locked->stage,
                ],
                actorType: $actor !== null ? 'user' : null,
                actorId: $actor?->id,
                actorLabel: $actor !== null ? $this->actorLabel($actor) : null,
            ));

            VpsPlanChangeFailed::dispatch(
                $locked->id,
                $locked->public_id,
                $locked->virtual_machine_id,
                $locked->user_id,
                $locked->from_version_id,
                $locked->to_version_id,
                $errorCode,
                $errorMessage,
                $locked->stage,
                $actor !== null ? $this->eventActor($actor) : null,
            );

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * Transition an applying plan change to ReconciliationRequired and optionally record a DriftReport.
     *
     * @param  array<string, mixed>|null  $driftDetails
     */
    public function markReconciliationRequired(
        VpsPlanChange $change,
        string $errorCode,
        string $errorMessage,
        ?string $stage = null,
        ?User $actor = null,
        ?array $driftDetails = null,
    ): VpsPlanChange {
        return DB::transaction(function () use ($change, $errorCode, $errorMessage, $stage, $actor, $driftDetails): VpsPlanChange {
            /** @var VpsPlanChange $locked */
            $locked = VpsPlanChange::query()
                ->where('id', $change->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status->equals(ReconciliationRequired::class)) {
                return $locked;
            }

            if (! $locked->status->canTransitionTo(ReconciliationRequired::class)) {
                throw new ConflictHttpException('Plan change cannot transition to reconciliation_required from its current state.');
            }

            $locked->error_code = $errorCode;
            $locked->error_message = $errorMessage;
            if ($stage !== null) {
                $locked->stage = $stage;
            }

            $locked->status->transitionTo(ReconciliationRequired::class);

            if ($driftDetails !== null) {
                /** @var VirtualMachine|null $vm */
                $vm = VirtualMachine::query()->find($locked->virtual_machine_id);
                if ($vm !== null && $vm->host_id !== null) {
                    $expectedJson = json_encode($locked->new_resources);
                    $actualJson = json_encode($driftDetails['observed'] ?? $driftDetails);

                    DriftReport::query()->create([
                        'host_id' => $vm->host_id,
                        'virtual_machine_id' => $vm->id,
                        'instance_name' => $vm->name,
                        'incus_project' => $vm->incus_project !== '' ? $vm->incus_project : 'default',
                        'kind' => 'resource_mismatch',
                        'expected_state' => is_string($expectedJson) ? $expectedJson : null,
                        'actual_state' => is_string($actualJson) ? $actualJson : null,
                        'details' => $driftDetails,
                        'resolved_at' => null,
                    ]);
                }
            }

            VpsPlanChangeReconciliationRequired::dispatch(
                $locked->id,
                $locked->public_id,
                $locked->virtual_machine_id,
                $locked->user_id,
                $locked->from_version_id,
                $locked->to_version_id,
                $errorCode,
                $errorMessage,
                $locked->stage,
                $actor !== null ? $this->eventActor($actor) : null,
            );

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * Commit the target plan version and resource columns to the VirtualMachine,
     * mark the plan change Completed, record audit, and dispatch domain events after commit.
     */
    public function commitCompleted(VpsPlanChange $change, ?User $actor = null): VpsPlanChange
    {
        return DB::transaction(function () use ($change, $actor): VpsPlanChange {
            /** @var VpsPlanChange $locked */
            $locked = VpsPlanChange::query()
                ->where('id', $change->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status->equals(Completed::class)) {
                return $locked;
            }

            if (! $locked->status->canTransitionTo(Completed::class)) {
                throw new ConflictHttpException('Plan change cannot transition to completed from its current state.');
            }

            /** @var VirtualMachine $lockedVm */
            $lockedVm = VirtualMachine::query()
                ->where('id', $locked->virtual_machine_id)
                ->lockForUpdate()
                ->firstOrFail();

            $oldResources = $locked->old_resources;
            $newResources = $locked->new_resources;

            $lockedVm->cpu_cores = Cast::int($newResources['cpu_cores'] ?? $lockedVm->cpu_cores);
            $lockedVm->ram_mb = Cast::int($newResources['ram_mb'] ?? $lockedVm->ram_mb);
            $lockedVm->disk_gb = Cast::int($newResources['disk_gb'] ?? $lockedVm->disk_gb);
            $lockedVm->net_download_mbps = array_key_exists('net_download_mbps', $newResources) && $newResources['net_download_mbps'] !== null
                ? Cast::int($newResources['net_download_mbps'])
                : null;
            $lockedVm->net_upload_mbps = array_key_exists('net_upload_mbps', $newResources) && $newResources['net_upload_mbps'] !== null
                ? Cast::int($newResources['net_upload_mbps'])
                : null;
            $lockedVm->vps_plan_version_id = $locked->to_version_id;
            $lockedVm->save();

            $locked->stage = 'committing';
            $locked->error_code = null;
            $locked->error_message = null;
            $locked->completed_at = Carbon::now();
            $locked->status->transitionTo(Completed::class);

            $this->logger()->record(new AuditEntry(
                action: AuditAction::VpsPlanChangeCompleted,
                outcome: AuditOutcome::Success,
                subject: $locked,
                tenantUserId: $locked->user_id,
                visibility: 'tenant',
                context: [
                    'vps_plan_change_id' => $locked->id,
                    'public_id' => $locked->public_id,
                    'virtual_machine_id' => $lockedVm->id,
                    'from_version_id' => $locked->from_version_id,
                    'to_version_id' => $locked->to_version_id,
                    'old_resources' => $oldResources,
                    'new_resources' => $newResources,
                ],
                actorType: $actor !== null ? 'user' : null,
                actorId: $actor?->id,
                actorLabel: $actor !== null ? $this->actorLabel($actor) : null,
            ));

            VpsPlanChangeCompleted::dispatch(
                $locked->id,
                $locked->public_id,
                $lockedVm->id,
                $locked->user_id,
                $locked->from_version_id,
                $locked->to_version_id,
                $oldResources,
                $newResources,
                $actor !== null ? $this->eventActor($actor) : null,
            );

            InstanceResized::dispatch(
                $lockedVm->id,
                $this->toInstanceResizedSpecs($oldResources),
                $this->toInstanceResizedSpecs($newResources),
                $actor !== null ? $this->eventActor($actor) : null,
                $locked->public_id,
                $locked->from_version_id,
                $locked->to_version_id,
            );

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * Resolve a plan change stuck in reconciliation_required by validating the requested
     * resolution action ('complete', 'fail', 'retry') against a fresh hypervisor read.
     *
     * @throws ValidationException
     */
    public function resolve(VpsPlanChange $change, string $action, ?string $note, User $admin): VpsPlanChange
    {
        if (! in_array($action, ['complete', 'fail', 'retry'], true)) {
            throw ValidationException::withMessages([
                'action' => ['Resolution action must be one of: complete, fail, retry.'],
            ]);
        }

        $freshChange = $change->fresh(['virtualMachine.host']);
        if ($freshChange === null || ! $freshChange->status->equals(ReconciliationRequired::class)) {
            throw new ConflictHttpException('Only plan changes requiring reconciliation can be resolved.');
        }

        $vm = $freshChange->virtualMachine;
        if ($vm === null) {
            throw new ConflictHttpException('Virtual machine associated with this plan change no longer exists.');
        }

        try {
            $observed = $this->hypervisorManager->driverForVm($vm)->getInstanceResourceSpec($vm);
        } catch (DriverUnavailableException|NoHypervisorAvailableException) {
            throw ValidationException::withMessages([
                'hypervisor' => ['Unable to read current instance resources from the compute host.'],
            ]);
        }

        $observedArray = $this->specToResourceArray($observed);

        if ($action === 'complete') {
            if (! $this->matchesResources($observed, $freshChange->new_resources)) {
                throw ValidationException::withMessages([
                    'action' => ['Observed hypervisor resources do not match the target plan specification.'],
                ]);
            }

            return DB::transaction(function () use ($freshChange, $note, $admin, $observedArray): VpsPlanChange {
                $completed = $this->commitCompleted($freshChange, $admin);
                $this->resolveOpenResourceDrift($completed->virtual_machine_id);
                $this->recordResolvedAudit($completed, 'complete', $note, $admin, $observedArray);

                return $completed;
            });
        }

        if ($action === 'fail') {
            if (! $this->matchesResources($observed, $freshChange->old_resources)) {
                throw ValidationException::withMessages([
                    'action' => ['Observed hypervisor resources do not match the original plan specification.'],
                ]);
            }

            return DB::transaction(function () use ($freshChange, $note, $admin, $observedArray): VpsPlanChange {
                $message = ($note !== null && trim($note) !== '')
                    ? trim($note)
                    : 'Plan change manually marked as failed after reconciliation.';

                $failed = $this->markFailed(
                    change: $freshChange,
                    errorCode: 'RECONCILED_FAILED',
                    errorMessage: $message,
                    stage: $freshChange->stage,
                    actor: $admin,
                );
                $this->resolveOpenResourceDrift($failed->virtual_machine_id);
                $this->recordResolvedAudit($failed, 'fail', $note, $admin, $observedArray);

                return $failed;
            });
        }

        // Action: retry
        if (! ($vm->status->equals(VmRunning::class) || $vm->status->equals(VmStopped::class))) {
            throw ValidationException::withMessages([
                'vm' => ['Virtual machine must be running or stopped to retry a plan change.'],
            ]);
        }

        return DB::transaction(function () use ($freshChange, $note, $admin, $observedArray): VpsPlanChange {
            /** @var VpsPlanChange $locked */
            $locked = VpsPlanChange::query()
                ->where('id', $freshChange->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->equals(ReconciliationRequired::class)) {
                throw new ConflictHttpException('Only plan changes requiring reconciliation can be retried.');
            }

            $deadlineMinutes = max(1, Cast::int(config('vps.apply_deadline_minutes', 20), 20));
            $locked->stage = 'validating';
            $locked->driver_operation_id = null;
            $locked->error_code = null;
            $locked->error_message = null;
            $locked->apply_deadline = Carbon::now()->addMinutes($deadlineMinutes);
            $locked->status->transitionTo(Applying::class);

            $this->resolveOpenResourceDrift($locked->virtual_machine_id);
            $this->recordResolvedAudit($locked, 'retry', $note, $admin, $observedArray);

            ApplyVpsPlanChangeJob::dispatch($locked->id, $locked->virtual_machine_id)->afterCommit();

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * Compare an observed hypervisor InstanceResourceSpec against a stored resource snapshot
     * using exact byte units (MiB * 2^20, GiB * 2^30).
     *
     * @param  array<string, mixed>  $resources
     */
    public function matchesResources(InstanceResourceSpec $observed, array $resources): bool
    {
        $expectedCpu = Cast::int($resources['cpu_cores'] ?? 0);
        $expectedRamBytes = Cast::int($resources['ram_mb'] ?? 0) * self::BYTES_PER_MIB;
        $expectedDiskBytes = Cast::int($resources['disk_gb'] ?? 0) * self::BYTES_PER_GIB;
        $expectedDownload = array_key_exists('net_download_mbps', $resources) && $resources['net_download_mbps'] !== null
            ? Cast::int($resources['net_download_mbps'])
            : null;
        $expectedUpload = array_key_exists('net_upload_mbps', $resources) && $resources['net_upload_mbps'] !== null
            ? Cast::int($resources['net_upload_mbps'])
            : null;

        $observedRamBytes = $observed->ramMb * self::BYTES_PER_MIB;
        $observedDiskBytes = $observed->diskGb * self::BYTES_PER_GIB;
        $observedDownload = $observed->network?->downloadMbps;
        $observedUpload = $observed->network?->uploadMbps;

        return $observed->cpuCores === $expectedCpu
            && $observedRamBytes === $expectedRamBytes
            && $observedDiskBytes === $expectedDiskBytes
            && $observedDownload === $expectedDownload
            && $observedUpload === $expectedUpload;
    }

    /**
     * Convert an InstanceResourceSpec to the canonical resource array format.
     *
     * @return array{
     *     cpu_cores: int,
     *     ram_mb: int,
     *     disk_gb: int,
     *     net_download_mbps: int|null,
     *     net_upload_mbps: int|null,
     * }
     */
    public function specToResourceArray(InstanceResourceSpec $spec): array
    {
        return [
            'cpu_cores' => $spec->cpuCores,
            'ram_mb' => $spec->ramMb,
            'disk_gb' => $spec->diskGb,
            'net_download_mbps' => $spec->network?->downloadMbps,
            'net_upload_mbps' => $spec->network?->uploadMbps,
        ];
    }

    /**
     * Record a denial audit entry and throw a ValidationException with HTTP 409 or 422 status.
     *
     * @param  list<array{code: string, message: string, kind?: string}>  $blockingReasons
     *
     * @throws ValidationException
     */
    private function denyRequest(
        VirtualMachine $vm,
        VpsPlanVersion $targetVersion,
        User $actor,
        array $blockingReasons,
    ): never {
        $firstReason = $blockingReasons[0] ?? [
            'code' => 'PLAN_CHANGE_BLOCKED',
            'message' => 'Plan change cannot be requested.',
        ];

        $this->logger()->recordIndependent(new AuditEntry(
            action: AuditAction::VpsPlanChangeDenied,
            outcome: AuditOutcome::Denied,
            subject: $vm,
            tenantUserId: $vm->user_id,
            visibility: 'tenant',
            context: [
                'virtual_machine_id' => $vm->id,
                'target_version_id' => $targetVersion->id,
                'blocking_reasons' => $blockingReasons,
            ],
            actorType: 'user',
            actorId: $actor->id,
            actorLabel: $this->actorLabel($actor),
        ));

        $isConflict = false;
        $errors = [];
        foreach ($blockingReasons as $reason) {
            if ($reason['code'] === 'ANOTHER_CHANGE_IN_PROGRESS') {
                $isConflict = true;
            }
            $field = $reason['kind'] ?? strtolower($reason['code']);
            $errors[$field][] = $reason['message'];
        }

        $status = $isConflict ? 409 : 422;
        $exception = ValidationException::withMessages($errors);
        $exception->status($status);
        $exception->response = response()->json([
            'message' => $firstReason['message'],
            'code' => $firstReason['code'],
            'blocking_reasons' => $blockingReasons,
            'errors' => $exception->errors(),
        ], $status);

        throw $exception;
    }

    /**
     * @param  array<string, mixed>  $observed
     */
    private function recordResolvedAudit(
        VpsPlanChange $change,
        string $action,
        ?string $note,
        User $admin,
        array $observed,
    ): void {
        $this->logger()->record(new AuditEntry(
            action: AuditAction::VpsPlanChangeResolved,
            outcome: AuditOutcome::Success,
            subject: $change,
            tenantUserId: $change->user_id,
            visibility: 'admin',
            context: [
                'vps_plan_change_id' => $change->id,
                'public_id' => $change->public_id,
                'virtual_machine_id' => $change->virtual_machine_id,
                'resolution_action' => $action,
                'note' => $note,
                'observed_resources' => $observed,
            ],
            actorType: 'user',
            actorId: $admin->id,
            actorLabel: $this->actorLabel($admin),
        ));
    }

    private function resolveOpenResourceDrift(int $virtualMachineId): void
    {
        DriftReport::query()
            ->where('virtual_machine_id', $virtualMachineId)
            ->where('kind', 'resource_mismatch')
            ->whereNull('resolved_at')
            ->update(['resolved_at' => Carbon::now()]);
    }

    /**
     * @return array{
     *     cpu_cores: int,
     *     ram_mb: int,
     *     disk_gb: int,
     *     net_download_mbps: int|null,
     *     net_upload_mbps: int|null,
     * }
     */
    private function vmResourceSnapshot(VirtualMachine $vm): array
    {
        return [
            'cpu_cores' => (int) $vm->cpu_cores,
            'ram_mb' => (int) $vm->ram_mb,
            'disk_gb' => (int) $vm->disk_gb,
            'net_download_mbps' => $vm->net_download_mbps !== null ? (int) $vm->net_download_mbps : null,
            'net_upload_mbps' => $vm->net_upload_mbps !== null ? (int) $vm->net_upload_mbps : null,
        ];
    }

    /**
     * @return array{
     *     cpu_cores: int,
     *     ram_mb: int,
     *     disk_gb: int,
     *     net_download_mbps: int|null,
     *     net_upload_mbps: int|null,
     * }
     */
    private function versionResourceSnapshot(VpsPlanVersion $version): array
    {
        return [
            'cpu_cores' => (int) $version->cpu_cores,
            'ram_mb' => (int) $version->ram_mb,
            'disk_gb' => (int) $version->disk_gb,
            'net_download_mbps' => $version->net_download_mbps !== null ? (int) $version->net_download_mbps : null,
            'net_upload_mbps' => $version->net_upload_mbps !== null ? (int) $version->net_upload_mbps : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $resources
     * @return array{cpu?: int, memory_mb?: int, disk_gb?: int, net_download_mbps?: int|null, net_upload_mbps?: int|null}
     */
    private function toInstanceResizedSpecs(array $resources): array
    {
        $specs = [
            'cpu' => Cast::int($resources['cpu_cores'] ?? 0),
            'memory_mb' => Cast::int($resources['ram_mb'] ?? 0),
            'disk_gb' => Cast::int($resources['disk_gb'] ?? 0),
        ];

        if (array_key_exists('net_download_mbps', $resources) && $resources['net_download_mbps'] !== null) {
            $specs['net_download_mbps'] = Cast::int($resources['net_download_mbps']);
        }

        if (array_key_exists('net_upload_mbps', $resources) && $resources['net_upload_mbps'] !== null) {
            $specs['net_upload_mbps'] = Cast::int($resources['net_upload_mbps']);
        }

        return $specs;
    }

    /**
     * Invoke the plan-change authorizer outside any database transaction or lock, bounded by the configured timeout.
     */
    private function authorizePlanChange(VpsPlanChangeAuthorizationRequest $authRequest): VpsPlanChangeAuthorizationResult
    {
        $timeoutSeconds = max(1, Cast::int(config('vps.authorizer_timeout_seconds', 3), 3));
        $startedAtNs = hrtime(true);
        $hasPcntl = function_exists('pcntl_alarm')
            && function_exists('pcntl_signal')
            && function_exists('pcntl_async_signals')
            && function_exists('pcntl_signal_get_handler');

        $previousAsync = false;
        $previousHandler = SIG_DFL;

        if ($hasPcntl) {
            $previousAsync = pcntl_async_signals(true);
            $previousHandler = pcntl_signal_get_handler(SIGALRM);
            pcntl_signal(SIGALRM, static function (): never {
                throw new RuntimeException('Plan-change authorizer timed out.');
            });
            pcntl_alarm($timeoutSeconds);
        }

        try {
            $result = $this->authorizer()->authorize($authRequest);
            $elapsedSeconds = (hrtime(true) - $startedAtNs) / 1_000_000_000;
            if ($elapsedSeconds > (float) $timeoutSeconds) {
                throw new RuntimeException('Plan-change authorizer exceeded configured timeout.');
            }

            return $result;
        } catch (Throwable $e) {
            throw new class('External authorization service is currently unavailable. (EXTENSION_AUTHORIZATION_UNAVAILABLE)', $e) extends HttpException
            {
                public function __construct(string $message, ?Throwable $previous = null)
                {
                    parent::__construct(503, $message, $previous);
                }

                public function render(): JsonResponse
                {
                    return response()->json([
                        'message' => $this->getMessage(),
                        'code' => 'EXTENSION_AUTHORIZATION_UNAVAILABLE',
                    ], 503);
                }
            };
        } finally {
            if ($hasPcntl) {
                pcntl_alarm(0);
                if ($previousHandler === SIG_IGN) {
                    pcntl_signal(SIGALRM, SIG_IGN);
                } elseif (is_callable($previousHandler)) {
                    pcntl_signal(SIGALRM, $previousHandler);
                } else {
                    pcntl_signal(SIGALRM, SIG_DFL);
                }
                pcntl_async_signals($previousAsync);
            }
        }
    }

    private function authorizer(): VpsPlanChangeAuthorizerInterface
    {
        return $this->authorizer ?? app(VpsPlanChangeAuthorizerInterface::class);
    }

    private function logger(): AuditLogger
    {
        return $this->auditLogger ??= app(AuditLogger::class);
    }

    private function actorLabel(User $actor): string
    {
        return $actor->email !== '' ? $actor->email : $actor->name;
    }

    /**
     * @return array{type: string, id: string|null, label: string|null}
     */
    private function eventActor(User $actor): array
    {
        $id = Cast::string($actor->getAuthIdentifier());
        $label = $this->actorLabel($actor);

        return [
            'type' => 'user',
            'id' => $id !== '' ? $id : null,
            'label' => $label !== '' ? $label : ($id !== '' ? "User #{$id}" : null),
        ];
    }
}
