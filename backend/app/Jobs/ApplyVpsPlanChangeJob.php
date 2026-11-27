<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Hypervisor\OperationState;
use App\Enums\Hypervisor\ResourceUpdateStatus;
use App\Enums\QueueName;
use App\Exceptions\DriverUnavailableException;
use App\Exceptions\NoHypervisorAvailableException;
use App\Models\Host;
use App\Models\VirtualMachine;
use App\Models\VpsPlanChange;
use App\Services\Hypervisor\Contracts\HypervisorDriverInterface;
use App\Services\Hypervisor\HypervisorManager;
use App\Services\Vps\VpsPlanChangeService;
use App\States\Host\Ok as HostOk;
use App\States\Vm\Running as VmRunning;
use App\States\Vm\Stopped as VmStopped;
use App\States\VpsPlanChange\Applying;
use App\States\VpsPlanChange\Pending;
use App\Support\Cast;
use App\ValueObjects\Hypervisor\InstanceResourceSpec;
use App\ValueObjects\Hypervisor\NetworkLimitsSpec;
use App\ValueObjects\Hypervisor\ResourceUpdateResult;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asynchronous job executing a VPS plan change against the hypervisor driver,
 * handling async operation polling, ETag conflict retries, verification, and drift reconciliation.
 */
final class ApplyVpsPlanChangeJob implements ShouldQueue
{
    use Queueable;

    private const int MAX_ETAG_RETRIES = 3;

    private const int POLL_DELAY_SECONDS = 5;

    /**
     * Unlimited attempts so async operation polling releases do not exhaust tries.
     */
    public int $tries = 0;

    /**
     * Guard against unhandled runtime crashes looping indefinitely.
     */
    public int $maxExceptions = 3;

    /**
     * Job timeout in seconds (strictly greater than vps.resource_update_wait_seconds).
     */
    public int $timeout = 120;

    public function __construct(
        public readonly int $planChangeId,
        public readonly ?int $virtualMachineId = null,
    ) {
        $this->onQueue(QueueName::High);
    }

    /**
     * Determine the deadline by which all attempts and async polling must conclude.
     */
    public function retryUntil(): DateTimeInterface
    {
        $change = VpsPlanChange::query()->find($this->planChangeId);
        if ($change !== null && $change->apply_deadline !== null) {
            return $change->apply_deadline;
        }

        $minutes = max(1, Cast::int(config('vps.apply_deadline_minutes', 20), 20));

        return Carbon::now()->addMinutes($minutes);
    }

    /**
     * Serialize all plan-change executions per virtual machine.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        $vmId = $this->virtualMachineId
            ?? VpsPlanChange::query()->where('id', $this->planChangeId)->value('virtual_machine_id');
        $lockKey = $vmId !== null ? sprintf('vm:%d', Cast::int($vmId)) : sprintf('plan_change:%d', $this->planChangeId);

        return [
            (new WithoutOverlapping($lockKey))
                ->releaseAfter(10)
                ->expireAfter(180),
        ];
    }

    /**
     * Execute or resume the plan-change workflow.
     */
    public function handle(VpsPlanChangeService $planChangeService, HypervisorManager $hypervisorManager): void
    {
        /** @var VpsPlanChange|null $change */
        $change = VpsPlanChange::query()
            ->with(['virtualMachine.host', 'toVersion'])
            ->find($this->planChangeId);

        if ($change === null) {
            return;
        }

        // Case 1: Fresh Pending request -> CAS transition Pending -> Applying
        if ($change->status->equals(Pending::class)) {
            $started = $planChangeService->startApplying($change);
            if ($started === null) {
                // Another worker or cancellation transitioned this change concurrently
                return;
            }
            $change = $started;
        } elseif ($change->status->equals(Applying::class)) {
            // Case 2a: Resuming async hypervisor operation polling after release()
            if ($change->driver_operation_id !== null && $change->stage === 'applying') {
                $this->pollAsyncOperation($change, $planChangeService, $hypervisorManager);

                return;
            }

            // Case 2b: Resuming verification stage
            if ($change->stage === 'verifying') {
                $vm = $change->virtualMachine;
                if ($vm === null) {
                    $planChangeService->markFailed($change, 'VM_NOT_FOUND', 'Virtual machine no longer exists.', 'verifying');

                    return;
                }

                try {
                    $driver = $hypervisorManager->driverForVm($vm);
                } catch (DriverUnavailableException|NoHypervisorAvailableException $e) {
                    $planChangeService->markReconciliationRequired(
                        change: $change,
                        errorCode: 'VERIFY_HOST_UNREACHABLE',
                        errorMessage: $e->getMessage(),
                        stage: 'verifying',
                        driftDetails: ['reason' => 'host_unreachable_during_verify', 'error' => $e->getMessage()],
                    );

                    return;
                }

                $this->verifyAndCommit($change, $vm, $planChangeService, $driver);

                return;
            }

            // Case 2c: Admin retry transitioned ReconciliationRequired -> Applying with stage = 'validating'
            if ($change->stage !== 'validating') {
                // Unexpected Applying state (e.g. another worker already running or stale without operation ID)
                return;
            }
        } else {
            // Terminal, AwaitingAuthorization, or ReconciliationRequired -> exit as no-op
            return;
        }

        $vm = $change->virtualMachine;
        if ($vm === null) {
            $planChangeService->markFailed($change, 'VM_NOT_FOUND', 'Virtual machine no longer exists.', 'validating');

            return;
        }

        // Spec 9.9: Plan assignment when from_version_id is null and all resources are unchanged
        if ($change->from_version_id === null && $this->resourcesUnchanged($change)) {
            try {
                $driver = $hypervisorManager->driverForVm($vm);
                $observed = $driver->getInstanceResourceSpec($vm);

                if ($planChangeService->matchesResources($observed, $change->new_resources)) {
                    $planChangeService->commitCompleted($change);

                    return;
                }
            } catch (DriverUnavailableException|NoHypervisorAvailableException $e) {
                $planChangeService->markFailed($change, 'HOST_UNREACHABLE', $e->getMessage(), 'validating');

                return;
            }
        }

        // Stage: validating (T8 pre-apply checks)
        $targetSpec = $this->buildTargetSpec($change, $vm);
        $driver = $this->runValidatingStage($change, $vm, $targetSpec, $planChangeService, $hypervisorManager);
        if ($driver === null) {
            return;
        }

        // Stage: applying (T9 / T10 / ETag conflict retries / async polling)
        $this->runApplyingStage($change, $vm, $targetSpec, $planChangeService, $driver);
    }

    /**
     * Handle terminal queue job failure (for example when retryUntil expires during polling).
     */
    public function failed(?Throwable $exception): void
    {
        Log::error(sprintf('ApplyVpsPlanChangeJob failed for plan change #%d: %s', $this->planChangeId, $exception?->getMessage() ?? 'unknown error'), [
            'plan_change_id' => $this->planChangeId,
            'exception' => $exception,
        ]);

        $change = VpsPlanChange::query()->with('virtualMachine.host')->find($this->planChangeId);
        if ($change === null || ! $change->status->equals(Applying::class)) {
            return;
        }

        $service = app(VpsPlanChangeService::class);
        $vm = $change->virtualMachine;

        if ($vm === null) {
            $service->markFailed($change, 'VM_NOT_FOUND', 'Virtual machine was removed before plan change completed.', $change->stage);

            return;
        }

        $exceptionMessage = $exception !== null && $exception->getMessage() !== ''
            ? $exception->getMessage()
            : null;

        try {
            $driver = app(HypervisorManager::class)->driverForVm($vm);
            $this->inspectAndHandleAmbiguousOutcome(
                change: $change,
                vm: $vm,
                planChangeService: $service,
                driver: $driver,
                fallbackCode: 'APPLY_JOB_FAILED',
                fallbackMessage: $exceptionMessage ?? 'Plan change job exceeded retry deadline or failed unexpectedly.',
            );
        } catch (Throwable $inner) {
            $service->markReconciliationRequired(
                change: $change,
                errorCode: 'APPLY_JOB_FAILED',
                errorMessage: $exceptionMessage ?? $inner->getMessage(),
                stage: $change->stage,
                driftDetails: [
                    'reason' => 'job_failed_and_hypervisor_unreachable',
                    'error' => $inner->getMessage(),
                ],
            );
        }
    }

    /**
     * Execute stage=validating checks and return the resolved driver, or null on T8 failure.
     */
    private function runValidatingStage(
        VpsPlanChange $change,
        VirtualMachine $vm,
        InstanceResourceSpec $targetSpec,
        VpsPlanChangeService $planChangeService,
        HypervisorManager $hypervisorManager,
    ): ?HypervisorDriverInterface {
        $vm->refresh();

        if (! ($vm->status->equals(VmRunning::class) || $vm->status->equals(VmStopped::class))) {
            $planChangeService->markFailed(
                change: $change,
                errorCode: 'VM_STATE_NOT_SUPPORTED',
                errorMessage: 'Virtual machine is not in a supported state (running or stopped) for plan change.',
                stage: 'validating',
            );

            return null;
        }

        /** @var Host|null $host */
        $host = $vm->host_id !== null ? Host::query()->find($vm->host_id) : null;
        if ($host === null || ! $host->is_active || ! $host->status->equals(HostOk::class)) {
            $planChangeService->markFailed(
                change: $change,
                errorCode: 'HOST_UNREACHABLE',
                errorMessage: 'Compute host is inactive or unreachable.',
                stage: 'validating',
            );

            return null;
        }

        try {
            $driver = $hypervisorManager->driverForVm($vm);
            $assessment = $driver->assessResourceUpdate($vm, $targetSpec);
        } catch (DriverUnavailableException|NoHypervisorAvailableException $e) {
            $planChangeService->markFailed(
                change: $change,
                errorCode: 'HOST_UNREACHABLE',
                errorMessage: $e->getMessage(),
                stage: 'validating',
            );

            return null;
        }

        if ($assessment->requiresStop()) {
            $planChangeService->markFailed(
                change: $change,
                errorCode: 'REQUIRES_SHUTDOWN_NOT_SUPPORTED',
                errorMessage: 'Resource update requires stopping the virtual machine first.',
                stage: 'validating',
            );

            return null;
        }

        if (! $assessment->isApplicable()) {
            $reasons = $assessment->reasons;
            $firstReason = reset($reasons);
            $errorCode = is_string($firstReason) && $firstReason !== '' ? $firstReason : 'DRIVER_OPERATION_UNSUPPORTED';

            $planChangeService->markFailed(
                change: $change,
                errorCode: $errorCode,
                errorMessage: 'Hypervisor driver reported that the requested resource update is unsupported.',
                stage: 'validating',
            );

            return null;
        }

        return $driver;
    }

    /**
     * Execute stage=applying with ETag retry loop and outcome handling.
     */
    private function runApplyingStage(
        VpsPlanChange $change,
        VirtualMachine $vm,
        InstanceResourceSpec $targetSpec,
        VpsPlanChangeService $planChangeService,
        HypervisorDriverInterface $driver,
    ): void {
        $change->stage = 'applying';
        $change->save();

        $result = new ResourceUpdateResult(
            status: ResourceUpdateStatus::Unknown,
            errorCode: 'EMPTY_DRIVER_RESULT',
            safeMessage: 'Hypervisor driver returned no result.',
        );
        $attempt = 0;

        while ($attempt <= self::MAX_ETAG_RETRIES) {
            if ($attempt > 0) {
                try {
                    $driver->getInstanceResourceSpec($vm);
                } catch (DriverUnavailableException $e) {
                    $result = new ResourceUpdateResult(
                        status: ResourceUpdateStatus::Unknown,
                        errorCode: 'DRIVER_UNAVAILABLE',
                        safeMessage: $e->getMessage(),
                    );

                    break;
                }
            }

            try {
                $result = $driver->applyResourceUpdate($vm, $targetSpec);
            } catch (Throwable $e) {
                $result = new ResourceUpdateResult(
                    status: ResourceUpdateStatus::Unknown,
                    errorCode: 'HYPERVISOR_EXCEPTION',
                    safeMessage: $e->getMessage(),
                );

                break;
            }

            if ($result->status === ResourceUpdateStatus::Rejected && $result->errorCode === 'CONCURRENT_MODIFICATION') {
                $attempt++;

                continue;
            }

            break;
        }

        if ($result->status === ResourceUpdateStatus::Applied) {
            if ($result->operationId !== null) {
                $change->driver_operation_id = $result->operationId;
            }
            $change->stage = 'verifying';
            $change->save();

            $this->verifyAndCommit($change, $vm, $planChangeService, $driver);

            return;
        }

        if ($result->status === ResourceUpdateStatus::Accepted) {
            $change->driver_operation_id = $result->operationId;
            $change->stage = 'applying';
            $change->save();

            $this->release(self::POLL_DELAY_SECONDS);

            return;
        }

        if ($result->status === ResourceUpdateStatus::Rejected) {
            $planChangeService->markFailed(
                change: $change,
                errorCode: $result->errorCode ?? 'RESOURCE_UPDATE_REJECTED',
                errorMessage: $result->safeMessage ?? 'Hypervisor rejected the resource update.',
                stage: 'applying',
            );

            return;
        }

        // ResourceUpdateStatus::Failed or ResourceUpdateStatus::Unknown -> inspect hypervisor truth
        $this->inspectAndHandleAmbiguousOutcome(
            change: $change,
            vm: $vm,
            planChangeService: $planChangeService,
            driver: $driver,
            fallbackCode: $result->errorCode ?? 'RESOURCE_UPDATE_FAILED',
            fallbackMessage: $result->safeMessage ?? 'Hypervisor resource update failed or returned an unknown outcome.',
        );
    }

    /**
     * Poll an asynchronous hypervisor operation until completion or apply_deadline.
     */
    private function pollAsyncOperation(
        VpsPlanChange $change,
        VpsPlanChangeService $planChangeService,
        HypervisorManager $hypervisorManager,
    ): void {
        $vm = $change->virtualMachine;
        if ($vm === null) {
            $planChangeService->markFailed($change, 'VM_NOT_FOUND', 'Virtual machine no longer exists.', 'applying');

            return;
        }

        try {
            $driver = $hypervisorManager->driverForVm($vm);
        } catch (DriverUnavailableException|NoHypervisorAvailableException $e) {
            $planChangeService->markReconciliationRequired(
                change: $change,
                errorCode: 'HOST_UNREACHABLE',
                errorMessage: $e->getMessage(),
                stage: 'applying',
                driftDetails: ['reason' => 'host_unreachable_during_poll', 'error' => $e->getMessage()],
            );

            return;
        }

        if ($change->apply_deadline !== null && Carbon::now()->greaterThanOrEqualTo($change->apply_deadline)) {
            $this->inspectAndHandleAmbiguousOutcome(
                change: $change,
                vm: $vm,
                planChangeService: $planChangeService,
                driver: $driver,
                fallbackCode: 'APPLY_DEADLINE_EXCEEDED',
                fallbackMessage: 'Plan change exceeded the apply deadline while waiting for the hypervisor operation.',
            );

            return;
        }

        $operationId = $change->driver_operation_id ?? '';
        try {
            $opStatus = $driver->getOperationStatus($vm, $operationId);
        } catch (Throwable $e) {
            $this->inspectAndHandleAmbiguousOutcome(
                change: $change,
                vm: $vm,
                planChangeService: $planChangeService,
                driver: $driver,
                fallbackCode: 'OPERATION_POLL_ERROR',
                fallbackMessage: $e->getMessage(),
            );

            return;
        }

        if ($opStatus->state === OperationState::Running) {
            $this->release(self::POLL_DELAY_SECONDS);

            return;
        }

        if ($opStatus->state === OperationState::Succeeded) {
            $change->stage = 'verifying';
            $change->save();

            $this->verifyAndCommit($change, $vm, $planChangeService, $driver);

            return;
        }

        $this->inspectAndHandleAmbiguousOutcome(
            change: $change,
            vm: $vm,
            planChangeService: $planChangeService,
            driver: $driver,
            fallbackCode: $opStatus->errorCode ?? 'HYPERVISOR_OPERATION_FAILED',
            fallbackMessage: $opStatus->safeMessage ?? 'Asynchronous hypervisor operation failed or returned unknown status.',
        );
    }

    /**
     * Stage verifying: read hypervisor observed spec and commit if exact match, else require reconciliation.
     */
    private function verifyAndCommit(
        VpsPlanChange $change,
        VirtualMachine $vm,
        VpsPlanChangeService $planChangeService,
        HypervisorDriverInterface $driver,
    ): void {
        try {
            $observed = $driver->getInstanceResourceSpec($vm);
        } catch (DriverUnavailableException $e) {
            $planChangeService->markReconciliationRequired(
                change: $change,
                errorCode: 'VERIFY_HOST_UNREACHABLE',
                errorMessage: $e->getMessage(),
                stage: 'verifying',
                driftDetails: ['reason' => 'host_unreachable_during_verify', 'error' => $e->getMessage()],
            );

            return;
        }

        if ($planChangeService->matchesResources($observed, $change->new_resources)) {
            $planChangeService->commitCompleted($change);

            return;
        }

        $observedArray = $planChangeService->specToResourceArray($observed);

        $planChangeService->markReconciliationRequired(
            change: $change,
            errorCode: 'VERIFY_RESOURCE_MISMATCH',
            errorMessage: 'Observed hypervisor resources after update do not match the target plan specification.',
            stage: 'verifying',
            driftDetails: [
                'plan_change_id' => $change->id,
                'public_id' => $change->public_id,
                'stage' => 'verifying',
                'expected' => $change->new_resources,
                'observed' => $observedArray,
            ],
        );
    }

    /**
     * Inspect hypervisor truth after a Failed or Unknown outcome and route to
     * Failed (== old), Completed (== new), or ReconciliationRequired + DriftReport (partial/unreachable).
     */
    private function inspectAndHandleAmbiguousOutcome(
        VpsPlanChange $change,
        VirtualMachine $vm,
        VpsPlanChangeService $planChangeService,
        HypervisorDriverInterface $driver,
        string $fallbackCode,
        string $fallbackMessage,
    ): void {
        try {
            $observed = $driver->getInstanceResourceSpec($vm);
        } catch (DriverUnavailableException $e) {
            $planChangeService->markReconciliationRequired(
                change: $change,
                errorCode: $fallbackCode,
                errorMessage: $fallbackMessage,
                stage: $change->stage ?? 'applying',
                driftDetails: [
                    'plan_change_id' => $change->id,
                    'public_id' => $change->public_id,
                    'reason' => 'hypervisor_unreachable_during_inspection',
                    'error' => $e->getMessage(),
                ],
            );

            return;
        }

        if ($planChangeService->matchesResources($observed, $change->new_resources)) {
            $planChangeService->commitCompleted($change);

            return;
        }

        if ($planChangeService->matchesResources($observed, $change->old_resources)) {
            $planChangeService->markFailed(
                change: $change,
                errorCode: $fallbackCode,
                errorMessage: $fallbackMessage,
                stage: $change->stage ?? 'applying',
            );

            return;
        }

        $observedArray = $planChangeService->specToResourceArray($observed);

        $planChangeService->markReconciliationRequired(
            change: $change,
            errorCode: $fallbackCode,
            errorMessage: $fallbackMessage,
            stage: $change->stage ?? 'applying',
            driftDetails: [
                'plan_change_id' => $change->id,
                'public_id' => $change->public_id,
                'stage' => $change->stage ?? 'applying',
                'old_resources' => $change->old_resources,
                'new_resources' => $change->new_resources,
                'observed' => $observedArray,
            ],
        );
    }

    private function resourcesUnchanged(VpsPlanChange $change): bool
    {
        $old = $change->old_resources;
        $new = $change->new_resources;

        return Cast::int($old['cpu_cores'] ?? 0) === Cast::int($new['cpu_cores'] ?? 0)
            && Cast::int($old['ram_mb'] ?? 0) === Cast::int($new['ram_mb'] ?? 0)
            && Cast::int($old['disk_gb'] ?? 0) === Cast::int($new['disk_gb'] ?? 0)
            && ($old['net_download_mbps'] ?? null) === ($new['net_download_mbps'] ?? null)
            && ($old['net_upload_mbps'] ?? null) === ($new['net_upload_mbps'] ?? null);
    }

    private function buildTargetSpec(VpsPlanChange $change, VirtualMachine $vm): InstanceResourceSpec
    {
        $new = $change->new_resources;
        $download = array_key_exists('net_download_mbps', $new) && $new['net_download_mbps'] !== null
            ? Cast::int($new['net_download_mbps'])
            : null;
        $upload = array_key_exists('net_upload_mbps', $new) && $new['net_upload_mbps'] !== null
            ? Cast::int($new['net_upload_mbps'])
            : null;

        $network = ($download !== null || $upload !== null)
            ? new NetworkLimitsSpec(downloadMbps: $download, uploadMbps: $upload)
            : null;

        $instanceType = $change->toVersion->instance_type ?? $vm->instance_type;

        return new InstanceResourceSpec(
            cpuCores: Cast::int($new['cpu_cores'] ?? $vm->cpu_cores),
            ramMb: Cast::int($new['ram_mb'] ?? $vm->ram_mb),
            diskGb: Cast::int($new['disk_gb'] ?? $vm->disk_gb),
            instanceType: $instanceType,
            network: $network,
        );
    }
}
