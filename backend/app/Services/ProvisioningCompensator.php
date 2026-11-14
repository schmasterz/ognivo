<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DriftReport;
use App\Models\VirtualMachine;
use App\Services\Hypervisor\HypervisorManager;
use App\States\Vm\Failed;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ProvisioningCompensator
{
    /**
     * Best-effort, idempotent compensation for a failed VM provisioning.
     *
     * (a) Deletes the instance from the hypervisor if it exists.
     * (b) If deletion is confirmed (or 404/not found), releases the IP allocation and resets network status.
     * (c) If deletion fails/is unconfirmed, keeps IP allocated and records a DriftReport.
     * (d) Persists raw_failure_reason and sanitized failure_reason in metadata, and transitions status to Failed.
     */
    public static function compensate(VirtualMachine $vm, string $reason): void
    {
        Log::warning(sprintf(
            'ProvisioningCompensator: Compensating VM #%d (%s), reason: %s',
            $vm->id,
            $vm->name,
            $reason
        ));

        $deleteConfirmed = false;
        $deleteError = null;

        // (a) Delete instance from hypervisor if it exists (ignore 404 / missing)
        try {
            $manager = app(HypervisorManager::class);
            $driver = $manager->driverForVm($vm);
            $deleted = $driver->deleteInstance($vm);
            if ($deleted) {
                $deleteConfirmed = true;
            } else {
                $deleteError = 'Hypervisor driver deleteInstance returned false';
                Log::error(sprintf(
                    'ProvisioningCompensator: deleteInstance returned false for VM #%d (%s)',
                    $vm->id,
                    $vm->name
                ));
            }
        } catch (Throwable $e) {
            $deleteError = $e->getMessage();
            if ($e->getCode() === 404 || str_contains(strtolower($e->getMessage()), 'not found') || str_contains($e->getMessage(), '404')) {
                $deleteConfirmed = true;
            } else {
                Log::error(sprintf(
                    'ProvisioningCompensator: Best-effort deleteInstance failed for VM #%d (%s): %s',
                    $vm->id,
                    $vm->name,
                    $e->getMessage()
                ));
            }
        }

        // (b) Release IP allocation ONLY if deletion confirmed; otherwise retain and create DriftReport
        if ($deleteConfirmed) {
            try {
                $ipService = app(IpManagementService::class);
                $ipService->releaseIp($vm);
            } catch (Throwable $e) {
                Log::warning(sprintf(
                    'ProvisioningCompensator: Best-effort releaseIp failed for VM #%d (%s): %s',
                    $vm->id,
                    $vm->name,
                    $e->getMessage()
                ));
            }
        } else {
            Log::error(sprintf(
                'ProvisioningCompensator: Instance deletion not confirmed for VM #%d (%s); retaining IP allocation and logging drift report.',
                $vm->id,
                $vm->name
            ));

            try {
                if ($vm->host_id !== null) {
                    DriftReport::create([
                        'host_id' => $vm->host_id,
                        'virtual_machine_id' => $vm->id,
                        'instance_name' => $vm->name,
                        'incus_project' => $vm->incus_project !== '' ? $vm->incus_project : 'default',
                        'kind' => 'cleanup_failed',
                        'expected_state' => 'deleted',
                        'actual_state' => null,
                        'details' => [
                            'reason' => $reason,
                            'delete_error' => $deleteError ?? 'Hypervisor instance deletion could not be confirmed',
                        ],
                        'resolved_at' => null,
                    ]);
                }
            } catch (Throwable $e) {
                Log::error(sprintf(
                    'ProvisioningCompensator: Failed to create DriftReport for VM #%d (%s): %s',
                    $vm->id,
                    $vm->name,
                    $e->getMessage()
                ));
            }
        }

        // (c) Set network_status 'unassigned' if deleted, persist failure reasons, and transition status to Failed
        try {
            $vm->refresh();
            if ($deleteConfirmed) {
                $vm->network_status = 'unassigned';
                $vm->ipv4_address = null;
            }
            /** @var array<string, mixed> $existingMeta */
            $existingMeta = is_array($vm->metadata) ? $vm->metadata : [];
            $vm->metadata = array_merge($existingMeta, [
                'raw_failure_reason' => $reason,
                'failure_reason' => self::sanitizeFailureReason($reason),
                'failed_at' => now()->toIso8601String(),
            ]);
            if (! $vm->status->equals(Failed::class)) {
                $vm->status->transitionTo(Failed::class);
            }
            $vm->save();
        } catch (Throwable $e) {
            Log::error(sprintf(
                'ProvisioningCompensator: Failed to persist Failed state for VM #%d (%s): %s',
                $vm->id,
                $vm->name,
                $e->getMessage()
            ));
        }
    }

    /**
     * Sanitize an internal or hypervisor error reason into a safe, user-facing message.
     */
    public static function sanitizeFailureReason(string $reason): string
    {
        $lower = strtolower($reason);

        if (str_contains($lower, 'timeout') || str_contains($lower, 'timed out')) {
            return 'Instance provisioning timed out while awaiting network or hypervisor response. Please retry or contact support.';
        }

        if (
            str_contains($lower, 'capacity')
            || str_contains($lower, 'quota')
            || str_contains($lower, 'insufficient')
            || str_contains($lower, 'not enough')
        ) {
            return 'Instance provisioning failed due to insufficient host capacity or quota limit. Please contact support.';
        }

        if (str_contains($reason, 'Hypervisor rejected instance creation')) {
            return 'Hypervisor rejected instance creation. Please retry or contact support.';
        }

        if (str_contains($reason, 'Hypervisor rejected instance startup')) {
            return 'Hypervisor rejected instance startup. Please retry or contact support.';
        }

        if (str_contains($lower, 'image') || str_contains($lower, 'simplestreams')) {
            return 'Instance provisioning failed while retrieving or unpacking the OS image. Please retry or contact support.';
        }

        return 'Instance provisioning failed. Please retry or contact support.';
    }
}
