<?php

declare(strict_types=1);

namespace App\Services;

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
     * (a) Deletes the instance from the hypervisor if it exists (ignoring 404 / missing instance).
     * (b) Calls IpManagementService::releaseIp($vm).
     * (c) Sets network_status 'unassigned' and status Failed.
     */
    public static function compensate(VirtualMachine $vm, string $reason): void
    {
        Log::warning(sprintf(
            'ProvisioningCompensator: Compensating VM #%d (%s), reason: %s',
            $vm->id,
            $vm->name,
            $reason
        ));

        // (a) Delete instance from hypervisor if it exists (ignore 404 / missing)
        try {
            $manager = app(HypervisorManager::class);
            $driver = $manager->driverForVm($vm);
            $driver->deleteInstance($vm);
        } catch (Throwable $e) {
            Log::warning(sprintf(
                'ProvisioningCompensator: Best-effort deleteInstance failed for VM #%d (%s): %s',
                $vm->id,
                $vm->name,
                $e->getMessage()
            ));
        }

        // (b) Release IP allocation
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

        // (c) Set network_status 'unassigned', persist failure_reason in metadata, and transition status to Failed
        try {
            $vm->refresh();
            $vm->network_status = 'unassigned';
            $vm->ipv4_address = null;
            $existingMeta = is_array($vm->metadata) ? $vm->metadata : [];
            $vm->metadata = array_merge($existingMeta, [
                'failure_reason' => $reason,
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
}
