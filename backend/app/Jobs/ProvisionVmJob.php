<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\IpAllocation;
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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
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
        public int $pollAttempt = 0,
    ) {}

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
                } catch (Throwable $e) {
                    if ($this->isTransient($e)) {
                        throw $e;
                    }

                    ProvisioningCompensator::compensate($this->vm, $e->getMessage());

                    return;
                }
            }

            // Step 2: Start instance only if it is not running
            $status = $driver->getInstanceStatus($this->vm);
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
            $network = $state['network'] ?? [];
            $assignedIp = $this->vm->ipv4_address;

            $observedIp = $this->extractObservedIp($network);

            if ($observedIp !== null && $assignedIp !== null && $observedIp === $assignedIp) {
                // Network confirmed! Transition to Running with observed status
                $this->vm->network_status = 'observed';
                if (! $this->vm->status->equals(Running::class)) {
                    $this->vm->status->transitionTo(Running::class);
                }
                $this->vm->save();

                Log::info(sprintf('VM #%d (%s) network observed: %s. Provisioning complete.', $this->vm->id, $this->vm->name, $observedIp));

                return;
            }

            // Check if this VM belongs to a manual pool
            $allocation = $this->allocationId
                ? IpAllocation::with('pool')->find($this->allocationId)
                : $this->vm->primaryAllocation()->with('pool')->first();
            $isManualPool = ($allocation?->pool?->network_mode === 'manual');

            if ($isManualPool) {
                $this->vm->network_status = 'unverified';
                if (! $this->vm->status->equals(Running::class)) {
                    $this->vm->status->transitionTo(Running::class);
                }
                $this->vm->save();

                Log::info(sprintf('VM #%d (%s) in manual network mode completed provisioning with unverified status.', $this->vm->id, $this->vm->name));

                return;
            }

            if ($observedIp !== null && $assignedIp !== null && $observedIp !== $assignedIp) {
                // IP mismatch detected
                $this->vm->network_status = 'mismatch';
                if (! $this->vm->status->equals(Running::class)) {
                    $this->vm->status->transitionTo(Running::class);
                }
                $this->vm->save();

                Log::warning(sprintf('VM #%d (%s) IP mismatch: expected %s, observed %s.', $this->vm->id, $this->vm->name, $assignedIp, $observedIp));

                return;
            }

            // Address not yet visible (agent still starting or cloud-init running)
            if ($this->vm->network_wait_started_at === null) {
                $this->vm->network_wait_started_at = now();
                $this->vm->save();
            }

            $waitLimitConfig = config('provisioning.network_wait_seconds');
            $waitLimit = is_numeric($waitLimitConfig) ? (int) $waitLimitConfig : 120;

            $pollIntervalConfig = config('provisioning.poll_interval_seconds');
            $pollInterval = is_numeric($pollIntervalConfig) ? (int) $pollIntervalConfig : 10;

            $elapsed = now()->getTimestamp() - $this->vm->network_wait_started_at->getTimestamp();

            if ($elapsed >= $waitLimit) {
                // Elapsed time exceeded network_wait_seconds: mark unverified and VM Running (not an error)
                $this->vm->network_status = 'unverified';
                if (! $this->vm->status->equals(Running::class)) {
                    $this->vm->status->transitionTo(Running::class);
                }
                $this->vm->save();

                Log::info(sprintf('VM #%d (%s) completed with unverified network status after %ds timeout.', $this->vm->id, $this->vm->name, $waitLimit));

                return;
            }

            Log::info(sprintf('VM #%d (%s) waiting for network (%ds/%ds). Releasing for %ds.', $this->vm->id, $this->vm->name, $elapsed, $waitLimit, $pollInterval));
            $this->release($pollInterval);
        });
    }

    /**
     * Determine if an exception represents a transient failure that should be retried.
     */
    protected function isTransient(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        if ($e instanceof RequestException) {
            $status = $e->response->status();

            if ($status === 408 || $status === 429) {
                return true;
            }

            return $status >= 500;
        }

        $message = strtolower($e->getMessage());
        foreach (['timeout', 'timed out', 'connection refused', 'connection reset', 'temporarily unavailable', '502 bad gateway', '503 service unavailable', '504 gateway timeout'] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        if (str_contains($message, 'permanent') || str_contains($message, 'rejected')) {
            return false;
        }

        return true;
    }

    /**
     * Extract the primary observed IPv4 address from the Incus network state array.
     *
     * @param  array<string, mixed>  $network
     */
    protected function extractObservedIp(array $network): ?string
    {
        foreach ($network as $device => $info) {
            if (! is_array($info)) {
                continue;
            }

            $addresses = $info['addresses'] ?? [];
            if (! is_array($addresses)) {
                continue;
            }

            foreach ($addresses as $addr) {
                if (! is_array($addr)) {
                    continue;
                }

                $family = $addr['family'] ?? '';
                $ip = $addr['address'] ?? '';
                $scope = $addr['scope'] ?? '';

                if ($family === 'inet' && is_string($ip) && $ip !== '' && $scope === 'global' && ! str_starts_with($ip, '127.')) {
                    return $ip;
                }
            }
        }

        return null;
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
