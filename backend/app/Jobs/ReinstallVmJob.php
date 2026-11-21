<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\IpAllocation;
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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asynchronous job to reinstall a virtual machine or container via hypervisor,
 * recreating the instance from scratch while preserving IP allocations and networking.
 */
final class ReinstallVmJob implements ShouldQueue
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

    /**
     * @param  array<string, mixed>|string|null  $cloudInit
     */
    public function __construct(
        public readonly VirtualMachine $vm,
        public readonly ?string $osImage = null,
        public readonly array|string|null $cloudInit = null,
        public bool $instanceDeleted = false,
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
            if ($reinstallInProgress && $this->vm->status->equals(Running::class) && in_array($this->vm->network_status, ['observed', 'unverified'], true)) {
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
                $this->vm->network_status = 'assigned';

                if (! $this->vm->status->equals(Provisioning::class)) {
                    $this->vm->status->transitionTo(Provisioning::class);
                }

                $this->vm->save();
            }

            // Step 1: Create instance ONLY when the driver reports it does not exist ('Unknown')
            $status = $driver->getInstanceStatus($this->vm);
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
                } catch (Throwable $e) {
                    if ($this->isTransient($e)) {
                        throw $e;
                    }

                    $this->failVm($e->getMessage());

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
            /** @var array<string, mixed> $network */
            $network = isset($state['network']) && is_array($state['network']) ? $state['network'] : [];
            $assignedIp = $this->vm->ipv4_address;

            $observedIp = $this->extractObservedIp($network);

            if ($observedIp !== null && $assignedIp !== null && $observedIp === $assignedIp) {
                // Network confirmed! Transition to Running with observed status
                $this->completeReinstall('observed');

                Log::info(sprintf('VM #%d (%s) network observed: %s. Reinstallation complete.', $this->vm->id, $this->vm->name, $observedIp));

                return;
            }

            // Check if this VM belongs to a manual pool
            $allocation = $this->allocationId !== null
                ? IpAllocation::with('pool')->find($this->allocationId)
                : $this->vm->primaryAllocation()->with('pool')->first();
            $isManualPool = ($allocation?->pool?->network_mode === 'manual');

            if ($isManualPool) {
                $this->completeReinstall('unverified');

                Log::info(sprintf('VM #%d (%s) in manual network mode completed reinstall with unverified status.', $this->vm->id, $this->vm->name));

                return;
            }

            if ($observedIp !== null && $assignedIp !== null && $observedIp !== $assignedIp) {
                // IP mismatch detected
                $this->completeReinstall('mismatch');

                Log::warning(sprintf('VM #%d (%s) IP mismatch during reinstall: expected %s, observed %s.', $this->vm->id, $this->vm->name, $assignedIp, $observedIp));

                return;
            }

            // Address not yet visible (agent still starting or cloud-init running)
            if ($this->vm->network_wait_started_at === null) {
                $this->vm->network_wait_started_at = Carbon::now();
                $this->vm->save();
            }

            $waitLimitConfig = config('provisioning.network_wait_seconds');
            $waitLimit = is_numeric($waitLimitConfig) ? (int) $waitLimitConfig : 120;

            $pollIntervalConfig = config('provisioning.poll_interval_seconds');
            $pollInterval = is_numeric($pollIntervalConfig) ? (int) $pollIntervalConfig : 10;

            $elapsed = now()->getTimestamp() - $this->vm->network_wait_started_at->getTimestamp();

            if ($elapsed >= $waitLimit) {
                // Elapsed time exceeded network_wait_seconds: mark unverified and VM Running (not an error)
                $this->completeReinstall('unverified');

                Log::info(sprintf('VM #%d (%s) completed reinstall with unverified network status after %ds timeout.', $this->vm->id, $this->vm->name, $waitLimit));

                return;
            }

            Log::info(sprintf('VM #%d (%s) waiting for network during reinstall (%ds/%ds). Releasing for %ds.', $this->vm->id, $this->vm->name, $elapsed, $waitLimit, $pollInterval));
            $this->pollAttempt++;
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
     * Clear the reinstall flag, update network status, and transition the VM to Running.
     */
    private function completeReinstall(string $networkStatus): void
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
