<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\IpAllocationStatus;
use App\Enums\QueueName;
use App\Exceptions\NoHypervisorAvailableException;
use App\Models\Host;
use App\Models\IpAllocation;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\Hypervisor\HypervisorManager;
use App\Services\IpManagementService;
use App\Services\VmLifecycleService;
use App\States\User\DeletionFailed;
use App\States\Vm\Deleted;
use App\States\Vm\Stopped;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asynchronously deletes tenant workloads, Incus projects, frees IPs, and removes DB rows.
 */
final class DeleteUserWorkloadsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    /**
     * Retry backoff delays in seconds.
     *
     * @var list<int>
     */
    public array $backoff = [10, 30, 60];

    public function __construct(
        public readonly User $user,
    ) {
        $this->onQueue(QueueName::Heavy);
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("user-delete:{$this->user->id}"))
                ->releaseAfter(30)
                ->expireAfter(600),
        ];
    }

    /**
     * Execute the job: delete hypervisor instances, Incus project, free IPs, then remove DB rows.
     */
    public function handle(
        VmLifecycleService $lifecycleService,
        HypervisorManager $hypervisorManager,
        ?IpManagementService $ipManagementService = null,
    ): void {
        $ipManagementService ??= app(IpManagementService::class);
        Log::info(sprintf('Starting deletion of user workloads for user #%d (%s)', $this->user->id, $this->user->email));

        try {
            // 1. Delete instances on hypervisor
            /** @var Collection<int, VirtualMachine> $vms */
            $vms = $this->user->virtualMachines()->with('host')->get();

            foreach ($vms as $vm) {
                if ($vm->status->equals(Deleted::class)) {
                    continue;
                }

                // Attempt graceful stop, then retry with force stop if needed
                $this->stopInstanceSafely($lifecycleService, $vm);

                $lifecycleService->delete($vm, force: true);
            }

            // 2. Delete Incus project(s) on hypervisor
            $this->deleteUserProjects($hypervisorManager, $vms);

            // 3. Free IPs via IpManagementService to preserve AuditAction::IpReleased and VM network_status sync
            foreach ($vms as $vm) {
                $ipManagementService->releaseIp($vm);
            }

            $remainingAllocations = IpAllocation::with('virtualMachine')
                ->where('user_id', $this->user->id)
                ->where('status', IpAllocationStatus::Allocated->value)
                ->get();

            foreach ($remainingAllocations as $allocation) {
                if ($allocation->virtualMachine !== null) {
                    $ipManagementService->releaseIp($allocation->virtualMachine);
                } else {
                    $ipManagementService->emergencyReleaseAllocation($allocation);
                }
            }

            // 4. Only then remove DB rows
            $deletedUserId = (int) $this->user->id;
            DB::transaction(function (): void {
                $this->user->virtualMachines()->delete();
                $this->user->quota()->delete();
                $this->user->delete();
            });

            // 5. Scrub PII and pseudonymise historical audit records for the deleted user
            try {
                Artisan::call('audit:pseudonymise', ['userId' => $deletedUserId]);
            } catch (Throwable $e) {
                Log::error(sprintf('Error pseudonymising audit logs for deleted user #%d: %s', $deletedUserId, $e->getMessage()));
            }

            Log::info(sprintf('Successfully completed deletion of user #%d and all associated infrastructure', $deletedUserId));
        } catch (Throwable $e) {
            Log::error(sprintf('Hypervisor failure while deleting user #%d: %s', $this->user->id, $e->getMessage()), [
                'exception' => $e,
            ]);

            // Hypervisor failure leaves user in visible "deletion_failed" state without losing VM rows
            if (! $this->user->status->equals(DeletionFailed::class)) {
                $this->user->status->transitionTo(DeletionFailed::class);
            }

            throw $e;
        }
    }

    /**
     * Delete Incus project(s) on relevant hypervisor host(s).
     *
     * @param  Collection<int, VirtualMachine>  $vms
     */
    protected function deleteUserProjects(
        HypervisorManager $hypervisorManager,
        $vms,
    ): void {
        $projectNames = [];
        if (! empty($this->user->project_name) && $this->user->project_name !== 'default') {
            $projectNames[] = $this->user->project_name;
        }
        foreach ($vms as $vm) {
            if (! empty($vm->incus_project) && $vm->incus_project !== 'default') {
                $projectNames[] = $vm->incus_project;
            }
        }
        $projectNames = array_values(array_unique(array_filter($projectNames)));

        if (empty($projectNames)) {
            return;
        }

        $hosts = $vms->pluck('host')->filter()->unique('id');
        if ($hosts->isEmpty()) {
            $hosts = Host::where('is_active', true)->get();
        }

        if ($hosts->isEmpty()) {
            try {
                $driver = $hypervisorManager->driverForHost(null);
                foreach ($projectNames as $projectName) {
                    $success = $driver->deleteProject($projectName);
                    if (! $success) {
                        throw new \RuntimeException(sprintf('Hypervisor failed to delete project "%s"', $projectName));
                    }
                }
            } catch (Throwable $e) {
                if (! $this->is404Exception($e) && ! ($e instanceof NoHypervisorAvailableException)) {
                    throw $e;
                }
            }

            return;
        }

        foreach ($hosts as $host) {
            $driver = $hypervisorManager->driverForHost($host);
            foreach ($projectNames as $projectName) {
                try {
                    $success = $driver->deleteProject($projectName);
                    if (! $success) {
                        throw new \RuntimeException(sprintf('Hypervisor failed to delete project "%s" on host #%d', $projectName, $host->id));
                    }
                } catch (Throwable $e) {
                    if (! $this->is404Exception($e)) {
                        throw $e;
                    }
                }
            }
        }
    }

    private function is404Exception(Throwable $e): bool
    {
        if ($e->getCode() === 404) {
            return true;
        }
        if ($e instanceof RequestException && $e->response->status() === 404) {
            return true;
        }
        if (method_exists($e, 'getStatusCode') && $e->getStatusCode() === 404) {
            return true;
        }

        return false;
    }

    /**
     * Safely attempt stopping an instance with graceful stop followed by force stop retry.
     */
    protected function stopInstanceSafely(VmLifecycleService $lifecycleService, VirtualMachine $vm): void
    {
        if ($vm->status->equals(Stopped::class) || $vm->status->equals(Deleted::class)) {
            return;
        }

        try {
            // First attempt graceful stop
            $lifecycleService->stop($vm, force: false);
        } catch (Throwable $e) {
            Log::warning(sprintf('Graceful stop failed for VM #%d (%s), retrying with force: %s', $vm->id, $vm->name, $e->getMessage()));
            try {
                // Retry with force stop (SIGKILL)
                $lifecycleService->stop($vm, force: true);
            } catch (Throwable $forceEx) {
                // If hypervisor returned 404 or instance already stopped, log and proceed
                Log::warning(sprintf('Force stop notice for VM #%d (%s): %s', $vm->id, $vm->name, $forceEx->getMessage()));
            }
        }
    }

    /**
     * Permanently mark failure state if retries are exhausted.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error(sprintf('DeleteUserWorkloadsJob failed for user #%d: %s', $this->user->id, $exception?->getMessage()), [
            'exception' => $exception,
        ]);

        $freshUser = User::find($this->user->id);
        if ($freshUser !== null && ! $freshUser->status->equals(DeletionFailed::class)) {
            $freshUser->status->transitionTo(DeletionFailed::class);
        }
    }
}
