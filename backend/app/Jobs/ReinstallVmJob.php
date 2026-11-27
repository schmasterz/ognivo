<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\NetworkStatus;
use App\Enums\QueueName;
use App\Jobs\Concerns\InteractsWithHypervisorNetwork;
use App\Models\VirtualMachine;
use App\Services\Hypervisor\HypervisorManager;
use App\States\Vm\Deleted;
use App\States\Vm\Deleting;
use App\States\Vm\Failed;
use App\States\Vm\Provisioning;
use App\States\Vm\Running;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asynchronous job to reinstall a virtual machine or container via hypervisor,
 * recreating the instance from scratch while preserving IP allocations and networking.
 */
final class ReinstallVmJob implements ShouldQueue
{
    use InteractsWithHypervisorNetwork;
    use Queueable;

    /**
     * Unlimited retry attempts for non-blocking polling releases.
     */
    public int $tries = 0;

    /**
     * Stop only on genuine unhandled exceptions.
     */
    public int $maxExceptions = 3;

    /**
     * Timeout for heavy provisioning operations (seconds).
     */
    public int $timeout = 300;

    /**
     * Time-based expiration for job execution attempts configured via provisioning config.
     */
    public function retryUntil(): DateTimeInterface
    {
        $deadline = config('provisioning.job_deadline_seconds');

        return now()->addSeconds(is_numeric($deadline) ? (int) $deadline : 900);
    }

    /**
     * @param  array<string, mixed>|string|null  $cloudInit
     */
    public function __construct(
        public readonly VirtualMachine $vm,
        public readonly ?string $osImage = null,
        public readonly array|string|null $cloudInit = null,
        public readonly ?int $allocationId = null,
    ) {
        $this->onQueue(QueueName::Heavy);
    }

    /**
     * Get the middleware the job should pass through.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("vm:{$this->vm->id}"))
                ->releaseAfter(10)
                ->expireAfter(300),
        ];
    }

    /**
     * Execute the reinstallation workflow.
     */
    public function handle(HypervisorManager $manager): void
    {
        Log::info(sprintf('Processing reinstallation step for VM #%d (%s)', $this->vm->id, $this->vm->name));

        $lock = Cache::lock("vm:{$this->vm->id}", 30);

        $lock->block(10, function () use ($manager): void {
            $this->vm->refresh();

            // Return immediately if the VM is being deleted or has been deleted
            if ($this->vm->status->equals(Deleting::class, Deleted::class)) {
                return;
            }

            $meta = is_array($this->vm->metadata) ? $this->vm->metadata : [];
            $reinstallInProgress = ($meta['reinstall_in_progress'] ?? false) === true;

            // If reinstallation was already initiated and VM is Failed, stop processing
            if ($reinstallInProgress && $this->vm->status->equals(Failed::class)) {
                return;
            }

            // If reinstallation was already initiated and VM has finished reinstallation
            if ($reinstallInProgress && $this->vm->status->equals(Running::class) && in_array($this->vm->network_status, [NetworkStatus::Observed, NetworkStatus::Unverified], true)) {
                return;
            }

            $driver = $manager->driverForVm($this->vm);

            // Step 0: Initial run - delete existing hypervisor instance, update configs, and transition state
            if (! $reinstallInProgress) {
                try {
                    $driver->deleteInstance($this->vm);
                } catch (Throwable $e) {
                    if ($this->isTransient($e)) {
                        throw $e;
                    }

                    Log::warning(sprintf(
                        'ReinstallVmJob: deleteInstance ignored error for VM #%d (%s): %s',
                        $this->vm->id,
                        $this->vm->name,
                        $e->getMessage()
                    ));
                }

                if ($this->osImage !== null && $this->osImage !== '') {
                    $this->vm->os_image = $this->osImage;
                }

                if ($this->cloudInit !== null) {
                    if (is_array($this->cloudInit)) {
                        $this->vm->cloud_init = $this->cloudInit;
                    } else {
                        /** @var mixed $decoded */
                        $decoded = json_decode($this->cloudInit, true);
                        /** @var array<string, mixed> $cloudInitData */
                        $cloudInitData = is_array($decoded) ? $decoded : ['user-data' => $this->cloudInit];
                        $this->vm->cloud_init = $cloudInitData;
                    }
                }

                $meta = is_array($this->vm->metadata) ? $this->vm->metadata : [];
                $meta['reinstall_in_progress'] = true;
                $this->vm->metadata = $meta;

                $this->vm->network_wait_started_at = null;
                $this->vm->network_status = NetworkStatus::Assigned;

                if (! $this->vm->status->equals(Provisioning::class)) {
                    $this->vm->status->transitionTo(Provisioning::class);
                }

                $this->vm->save();
            }

            // Step 1: Create instance ONLY when the driver reports it does not exist ('Unknown')
            $status = $driver->getInstanceStatus($this->vm);
            $justCreated = false;
            if ($status === 'Unknown') {
                try {
                    $created = $driver->createInstance($this->vm);
                    if (! $created) {
                        $driverError = method_exists($driver, 'getLastError') ? $driver->getLastError() : null;
                        $reason = is_string($driverError) && $driverError !== ''
                            ? sprintf('Hypervisor rejected instance creation during reinstall: %s', $driverError)
                            : 'Hypervisor rejected instance creation during reinstall.';
                        $this->failVm($reason);

                        return;
                    }
                    $justCreated = true;
                } catch (Throwable $e) {
                    if ($this->isTransient($e)) {
                        throw $e;
                    }

                    $this->failVm($e->getMessage());

                    return;
                }
            }

            // Step 2: Start instance only if it is not running
            $status = $justCreated ? 'Stopped' : $driver->getInstanceStatus($this->vm);
            if ($status !== 'Running') {
                try {
                    $started = $driver->startInstance($this->vm);
                    if (! $started) {
                        $driverError = method_exists($driver, 'getLastError') ? $driver->getLastError() : null;
                        $reason = is_string($driverError) && $driverError !== ''
                            ? sprintf('Hypervisor rejected instance startup during reinstall: %s', $driverError)
                            : 'Hypervisor rejected instance startup during reinstall.';
                        $this->failVm($reason);

                        return;
                    }
                } catch (Throwable $e) {
                    if ($this->isTransient($e)) {
                        throw $e;
                    }

                    $this->failVm($e->getMessage());

                    return;
                }
            }

            // Step 3: await_network - check observed IP on instance state
            $state = $driver->getInstanceState($this->vm);
            $observation = $this->evaluateNetworkObservation($this->vm, $this->allocationId, $state);

            if ($observation['status'] === NetworkStatus::Observed) {
                $this->completeReinstall(NetworkStatus::Observed);
                Log::info(sprintf('VM #%d (%s) network observed: %s. Reinstallation complete.', $this->vm->id, $this->vm->name, $observation['observed_ip'] ?? ''));

                return;
            }

            if ($observation['status'] === NetworkStatus::Unverified) {
                $this->completeReinstall(NetworkStatus::Unverified);

                if ($this->vm->network_wait_started_at !== null && $observation['elapsed'] >= $observation['wait_limit']) {
                    Log::info(sprintf('VM #%d (%s) completed reinstall with unverified network status after %ds timeout.', $this->vm->id, $this->vm->name, $observation['wait_limit']));
                } else {
                    Log::info(sprintf('VM #%d (%s) in manual network mode completed reinstall with unverified status.', $this->vm->id, $this->vm->name));
                }

                return;
            }

            if ($observation['status'] === NetworkStatus::Mismatch) {
                $this->completeReinstall(NetworkStatus::Mismatch);
                Log::warning(sprintf('VM #%d (%s) IP mismatch during reinstall: expected %s, observed %s.', $this->vm->id, $this->vm->name, $this->vm->ipv4_address ?? '', $observation['observed_ip'] ?? ''));

                return;
            }

            Log::info(sprintf('VM #%d (%s) waiting for network during reinstall (%ds/%ds). Releasing for %ds.', $this->vm->id, $this->vm->name, $observation['elapsed'], $observation['wait_limit'], $observation['poll_interval']));
            $this->release($observation['poll_interval']);
        });
    }

    /**
     * Clear the reinstall flag, update network status, and transition the VM to Running.
     */
    private function completeReinstall(NetworkStatus $networkStatus): void
    {
        $meta = is_array($this->vm->metadata) ? $this->vm->metadata : [];
        unset($meta['reinstall_in_progress']);
        $this->vm->metadata = $meta;
        $this->vm->network_status = $networkStatus;
        if (! $this->vm->status->equals(Running::class)) {
            $this->vm->status->transitionTo(Running::class);
        }
        $this->vm->save();
    }

    /**
     * Mark VM as Failed with failure_reason in metadata WITHOUT releasing IP allocation.
     */
    protected function failVm(string $reason): void
    {
        Log::error(sprintf(
            'ReinstallVmJob: Reinstallation failed for VM #%d (%s): %s',
            $this->vm->id,
            $this->vm->name,
            $reason
        ));

        try {
            $this->vm->refresh();
            $existingMeta = is_array($this->vm->metadata) ? $this->vm->metadata : [];
            unset($existingMeta['reinstall_in_progress']);
            $this->vm->metadata = array_merge($existingMeta, [
                'failure_reason' => $reason,
                'failed_at' => now()->toIso8601String(),
            ]);
            if (! $this->vm->status->equals(Failed::class)) {
                $this->vm->status->transitionTo(Failed::class);
            }
            $this->vm->save();
        } catch (Throwable $e) {
            Log::error(sprintf(
                'ReinstallVmJob: Failed to persist Failed state for VM #%d (%s): %s',
                $this->vm->id,
                $this->vm->name,
                $e->getMessage()
            ));
        }
    }

    /**
     * Handle a permanent job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error(sprintf('ReinstallVmJob failed permanently for VM #%d (%s): %s', $this->vm->id, $this->vm->name, $exception?->getMessage() ?? 'Unknown error'), [
            'exception' => $exception,
        ]);

        $this->failVm(
            $exception?->getMessage() ?? 'Reinstallation job deadline or retries exceeded.'
        );
    }
}
