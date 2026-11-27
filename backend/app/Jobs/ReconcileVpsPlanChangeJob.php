<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\QueueName;
use App\Exceptions\DriverUnavailableException;
use App\Exceptions\NoHypervisorAvailableException;
use App\Models\VpsPlanChange;
use App\Services\Hypervisor\HypervisorManager;
use App\Services\Vps\VpsPlanChangeService;
use App\States\VpsPlanChange\Applying;
use App\Support\Cast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

/**
 * Reconciles a stalled VPS plan-change operation stuck in the Applying state
 * by inspecting the live hypervisor resource specification.
 */
final class ReconcileVpsPlanChangeJob implements ShouldQueue
{
    use Queueable;

    /**
     * Maximum number of attempts before leaving the operation for the next sweep or manual reconciliation.
     */
    public int $tries = 3;

    /**
     * Job execution timeout in seconds.
     */
    public int $timeout = 60;

    public function __construct(
        public readonly int $planChangeId,
        public readonly ?int $virtualMachineId = null,
    ) {
        $this->onQueue(QueueName::High);
    }

    /**
     * Serialize plan-change reconciliation per virtual machine.
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
     * Inspect hypervisor state and transition the stalled Applying plan change accordingly.
     */
    public function handle(VpsPlanChangeService $planChangeService, HypervisorManager $hypervisorManager): void
    {
        /** @var VpsPlanChange|null $change */
        $change = VpsPlanChange::query()
            ->with(['virtualMachine.host'])
            ->find($this->planChangeId);

        if ($change === null || ! $change->status->equals(Applying::class)) {
            return;
        }

        $vm = $change->virtualMachine;
        if ($vm === null) {
            $planChangeService->markFailed(
                change: $change,
                errorCode: 'VM_NOT_FOUND',
                errorMessage: 'Virtual machine no longer exists.',
                stage: $change->stage,
            );

            return;
        }

        try {
            $driver = $hypervisorManager->driverForVm($vm);
            $observed = $driver->getInstanceResourceSpec($vm);
        } catch (DriverUnavailableException|NoHypervisorAvailableException $e) {
            Log::warning(sprintf('Hypervisor unavailable while reconciling plan change #%d: %s', $change->id, $e->getMessage()), [
                'plan_change_id' => $change->id,
                'public_id' => $change->public_id,
                'virtual_machine_id' => $vm->id,
            ]);

            if ($this->job !== null && $this->attempts() < $this->tries) {
                $this->release(30);
            }

            return;
        }

        if ($planChangeService->matchesResources($observed, $change->new_resources)) {
            $planChangeService->commitCompleted($change);

            return;
        }

        if ($planChangeService->matchesResources($observed, $change->old_resources)) {
            $planChangeService->markFailed(
                change: $change,
                errorCode: 'NOT_APPLIED',
                errorMessage: 'Hypervisor state matches previous plan resources.',
                stage: $change->stage,
            );

            return;
        }

        $observedArray = $planChangeService->specToResourceArray($observed);

        $planChangeService->markReconciliationRequired(
            change: $change,
            errorCode: 'RECONCILE_RESOURCE_MISMATCH',
            errorMessage: 'Observed hypervisor resources do not match either the previous or target plan specification.',
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
}
