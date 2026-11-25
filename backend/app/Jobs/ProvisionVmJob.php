<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\InteractsWithHypervisorNetwork;
use App\Jobs\Enums\QueueName;
use App\Models\VirtualMachine;
use App\Services\Hypervisor\HypervisorManager;
use App\Services\ProvisioningCompensator;
use App\States\Vm\Deleted;
use App\States\Vm\Deleting;
use App\States\Vm\Failed;
use App\States\Vm\Running;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asynchronous job to provision a virtual machine or container via hypervisor,
 * tracking network verification and maintaining idempotent state transitions under lock.
 */
final class ProvisionVmJob implements ShouldQueue
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

    public function __construct(
        public readonly VirtualMachine $vm,
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
     * Execute the provisioning workflow.
     */
    public function handle(HypervisorManager $manager): void
    {
        Log::info(sprintf('Processing provisioning step for VM #%d (%s)', $this->vm->id, $this->vm->name));

        $lock = Cache::lock("vm:{$this->vm->id}", 30);

        $lock->block(10, function () use ($manager): void {
            $this->vm->refresh();

            // Return immediately if the VM is already Failed, Deleting, or Deleted
            if ($this->vm->status->equals(Failed::class, Deleting::class, Deleted::class)) {
                return;
            }

            if ($this->vm->status->equals(Running::class) && in_array($this->vm->network_status, ['observed', 'unverified'], true)) {
                return;
            }

            $driver = $manager->driverForVm($this->vm);

            // Step 1: Create instance ONLY when the driver reports it does not exist ('Unknown')
            $status = $driver->getInstanceStatus($this->vm);
            $justCreated = false;
            if ($status === 'Unknown') {
                try {
                    $created = $driver->createInstance($this->vm);
                    if (! $created) {
                        $driverError = method_exists($driver, 'getLastError') ? $driver->getLastError() : null;
                        $reason = is_string($driverError) && $driverError !== ''
                            ? sprintf('Hypervisor rejected instance creation: %s', $driverError)
                            : 'Hypervisor rejected instance creation.';
                        ProvisioningCompensator::compensate($this->vm, $reason);

                        return;
                    }
                    $justCreated = true;
                } catch (Throwable $e) {
                    if ($this->isTransient($e)) {
                        throw $e;
                    }

                    ProvisioningCompensator::compensate($this->vm, $e->getMessage());

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
                            ? sprintf('Hypervisor rejected instance startup: %s', $driverError)
                            : 'Hypervisor rejected instance startup.';
                        ProvisioningCompensator::compensate($this->vm, $reason);

                        return;
                    }
                } catch (Throwable $e) {
                    if ($this->isTransient($e)) {
                        throw $e;
                    }

                    ProvisioningCompensator::compensate($this->vm, $e->getMessage());

                    return;
                }
            }

            // Step 3: await_network - check observed IP on instance state
            $state = $driver->getInstanceState($this->vm);
            $observation = $this->evaluateNetworkObservation($this->vm, $this->allocationId, $state);

            if ($observation['status'] === 'observed') {
                $this->completeProvisioning('observed');
                Log::info(sprintf('VM #%d (%s) network observed: %s. Provisioning complete.', $this->vm->id, $this->vm->name, $observation['observed_ip'] ?? ''));

                return;
            }

            if ($observation['status'] === 'unverified') {
                $this->completeProvisioning('unverified');

                if ($this->vm->network_wait_started_at !== null && $observation['elapsed'] >= $observation['wait_limit']) {
                    Log::info(sprintf('VM #%d (%s) completed with unverified network status after %ds timeout.', $this->vm->id, $this->vm->name, $observation['wait_limit']));
                } else {
                    Log::info(sprintf('VM #%d (%s) in manual network mode completed provisioning with unverified status.', $this->vm->id, $this->vm->name));
                }

                return;
            }

            if ($observation['status'] === 'mismatch') {
                $this->completeProvisioning('mismatch');
                Log::warning(sprintf('VM #%d (%s) IP mismatch: expected %s, observed %s.', $this->vm->id, $this->vm->name, $this->vm->ipv4_address ?? '', $observation['observed_ip'] ?? ''));

                return;
            }

            Log::info(sprintf('VM #%d (%s) waiting for network (%ds/%ds). Releasing for %ds.', $this->vm->id, $this->vm->name, $observation['elapsed'], $observation['wait_limit'], $observation['poll_interval']));
            $this->release($observation['poll_interval']);
        });
    }

    /**
     * Update network status and transition the VM to Running.
     */
    private function completeProvisioning(string $networkStatus): void
    {
        $this->vm->network_status = $networkStatus;
        if (! $this->vm->status->equals(Running::class)) {
            $this->vm->status->transitionTo(Running::class);
        }
        $this->vm->save();
    }

    /**
     * Handle a permanent job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error(sprintf('ProvisionVmJob failed permanently for VM #%d (%s): %s', $this->vm->id, $this->vm->name, $exception?->getMessage()), [
            'exception' => $exception,
        ]);

        ProvisioningCompensator::compensate(
            $this->vm,
            $exception?->getMessage() ?? 'Provisioning job deadline or retries exceeded.'
        );
    }
}
