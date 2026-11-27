<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Enums\NetworkStatus;
use App\Models\IpAllocation;
use App\Models\VirtualMachine;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Shared hypervisor network observation and transient failure helpers for VM jobs.
 */
trait InteractsWithHypervisorNetwork
{
    /**
     * Evaluate observed network state against assigned IP and pool mode.
     *
     * @param  array<string, mixed>  $state
     * @return array{status: NetworkStatus, observed_ip: string|null, elapsed: int, wait_limit: int, poll_interval: int}
     */
    protected function evaluateNetworkObservation(VirtualMachine $vm, ?int $allocationId, array $state): array
    {
        /** @var array<string, mixed> $network */
        $network = isset($state['network']) && is_array($state['network']) ? $state['network'] : [];
        $observedIp = $this->extractObservedIp($network);
        $assignedIp = $vm->ipv4_address;

        $waitLimitConfig = config('provisioning.network_wait_seconds');
        $waitLimit = is_numeric($waitLimitConfig) ? (int) $waitLimitConfig : 120;

        $pollIntervalConfig = config('provisioning.poll_interval_seconds');
        $pollInterval = is_numeric($pollIntervalConfig) ? (int) $pollIntervalConfig : 10;

        if ($observedIp !== null && $assignedIp !== null && $observedIp === $assignedIp) {
            return [
                'status' => NetworkStatus::Observed,
                'observed_ip' => $observedIp,
                'elapsed' => 0,
                'wait_limit' => $waitLimit,
                'poll_interval' => $pollInterval,
            ];
        }

        $allocation = $allocationId !== null
            ? IpAllocation::with('pool')->find($allocationId)
            : $vm->primaryAllocation()->with('pool')->first();

        if ($allocation?->pool?->network_mode === 'manual') {
            return [
                'status' => NetworkStatus::Unverified,
                'observed_ip' => $observedIp,
                'elapsed' => 0,
                'wait_limit' => $waitLimit,
                'poll_interval' => $pollInterval,
            ];
        }

        if ($observedIp !== null && $assignedIp !== null && $observedIp !== $assignedIp) {
            return [
                'status' => NetworkStatus::Mismatch,
                'observed_ip' => $observedIp,
                'elapsed' => 0,
                'wait_limit' => $waitLimit,
                'poll_interval' => $pollInterval,
            ];
        }

        if ($vm->network_wait_started_at === null) {
            $vm->network_wait_started_at = Carbon::now();
            $vm->save();
        }

        $elapsed = now()->getTimestamp() - $vm->network_wait_started_at->getTimestamp();

        return [
            'status' => $elapsed >= $waitLimit ? NetworkStatus::Unverified : NetworkStatus::Waiting,
            'observed_ip' => $observedIp,
            'elapsed' => $elapsed,
            'wait_limit' => $waitLimit,
            'poll_interval' => $pollInterval,
        ];
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
}
