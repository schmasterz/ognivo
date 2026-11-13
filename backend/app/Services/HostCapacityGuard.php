<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Host;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Service enforcing hard capacity boundaries (CPU, RAM, Disk) per compute host,
 * accounting for committed VM allocations, overcommit multipliers, and host overhead reserves.
 */
class HostCapacityGuard
{
    /**
     * Determine whether a host has verified, known capacity.
     */
    public function hasKnownCapacity(Host $host): bool
    {
        if ($host->capacity_source === 'unknown') {
            return false;
        }

        return $host->total_cpu_cores > 0
            && $host->total_ram_mb > 0
            && $host->total_storage_gb > 0;
    }

    /**
     * Compute committed usage on a host for non-failed, non-deleted VMs (including Provisioning).
     *
     * @return array{cpu: int, ram: int, disk: int}
     */
    public function getCommittedUsage(Host $host, bool $lock = false): array
    {
        $query = DB::table('virtual_machines')
            ->where('host_id', $host->id)
            ->whereNotIn('status', ['failed', 'deleted']);

        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var object{cpu: int|float|string|null, ram: int|float|string|null, disk: int|float|string|null} $usage */
        $usage = $query->selectRaw('COALESCE(SUM(cpu_cores), 0) as cpu, COALESCE(SUM(ram_mb), 0) as ram, COALESCE(SUM(disk_gb), 0) as disk')
            ->first();

        return [
            'cpu' => (int) ($usage->cpu ?? 0),
            'ram' => (int) ($usage->ram ?? 0),
            'disk' => (int) ($usage->disk ?? 0),
        ];
    }

    /**
     * Get maximum allocatable capacity per resource taking overcommit and reserves into account.
     *
     * @return array{cpu: float, ram: float, disk: float}
     */
    public function getMaxAllocatable(Host $host): array
    {
        $cpuOvercommit = (float) config('provisioning.cpu_overcommit', 1.0);
        if ($cpuOvercommit <= 0) {
            $cpuOvercommit = 1.0;
        }

        $ramOvercommit = (float) config('provisioning.ram_overcommit', 1.0);
        if ($ramOvercommit <= 0) {
            $ramOvercommit = 1.0;
        }

        $diskOvercommit = (float) config('provisioning.disk_overcommit', 1.0);
        if ($diskOvercommit <= 0) {
            $diskOvercommit = 1.0;
        }

        $cpuReserve = (int) config('provisioning.host_cpu_reserve_cores', 0);
        $ramReserve = (int) config('provisioning.host_ram_reserve_mb', 1024);
        $diskReserve = (int) config('provisioning.host_disk_reserve_gb', 0);

        return [
            'cpu' => ($host->total_cpu_cores * $cpuOvercommit) - $cpuReserve,
            'ram' => ($host->total_ram_mb * $ramOvercommit) - $ramReserve,
            'disk' => ($host->total_storage_gb * $diskOvercommit) - $diskReserve,
        ];
    }

    /**
     * Check whether the host can fit the requested CPU, RAM, and Disk specifications.
     */
    public function canFit(Host $host, int $requestedCpu, int $requestedRam, int $requestedDisk, bool $lock = false): bool
    {
        if (! $this->hasKnownCapacity($host)) {
            return false;
        }

        $committed = $this->getCommittedUsage($host, $lock);
        $max = $this->getMaxAllocatable($host);

        $fitsCpu = ($committed['cpu'] + $requestedCpu) <= $max['cpu'];
        $fitsRam = ($committed['ram'] + $requestedRam) <= $max['ram'];
        $fitsDisk = ($committed['disk'] + $requestedDisk) <= $max['disk'];

        return $fitsCpu && $fitsRam && $fitsDisk;
    }

    /**
     * Validate capacity for a specific host, throwing a ValidationException on failure.
     *
     * @throws ValidationException
     */
    public function check(Host $host, int $requestedCpu, int $requestedRam, int $requestedDisk, bool $lock = true): void
    {
        if (! $this->hasKnownCapacity($host)) {
            throw ValidationException::withMessages([
                'host_id' => [__('messages.host.capacity_unknown')],
            ]);
        }

        if (! $this->canFit($host, $requestedCpu, $requestedRam, $requestedDisk, lock: $lock)) {
            throw ValidationException::withMessages([
                'host_id' => [__('messages.host.insufficient_capacity')],
            ]);
        }
    }
}
