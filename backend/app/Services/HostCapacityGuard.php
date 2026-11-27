<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Host;
use App\Models\VirtualMachine;
use App\Services\Vps\ResourceReservationService;
use App\Support\Cast;
use App\ValueObjects\Hypervisor\InstanceResourceSpec;
use App\ValueObjects\Vps\CapacityCheckResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Service enforcing hard capacity boundaries (CPU, RAM, Disk) per compute host,
 * accounting for committed VM allocations, active plan-change reservations,
 * overcommit multipliers, and host overhead reserves.
 */
final class HostCapacityGuard
{
    public function __construct(
        private readonly ResourceReservationService $reservationService = new ResourceReservationService,
    ) {}

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
     * Compute committed usage on a host for non-failed, non-deleted VMs (including Provisioning)
     * plus positive resource reservations held by active VPS plan changes.
     *
     * @return array{cpu: int, ram: int, disk: int}
     */
    public function getCommittedUsage(Host $host, bool $lock = false, ?int $excludeChangeId = null): array
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

        $reservations = $this->reservationService->getHostReservations($host->id, $excludeChangeId, $lock);

        return [
            'cpu' => (int) ($usage->cpu ?? 0) + $reservations['cpu'],
            'ram' => (int) ($usage->ram ?? 0) + $reservations['ram'],
            'disk' => (int) ($usage->disk ?? 0) + $reservations['disk'],
        ];
    }

    /**
     * Compute committed usage (including active plan-change reservations) for multiple host IDs in batch queries.
     *
     * @param  array<int>  $hostIds
     * @return array<int, array{cpu: int, ram: int, disk: int}>
     */
    public function getCommittedUsageForHostIds(array $hostIds): array
    {
        $uniqueHostIds = array_values(array_unique(array_filter(
            array_map(fn (mixed $id): int => Cast::int($id), $hostIds),
            fn (int $id): bool => $id > 0
        )));

        if ($uniqueHostIds === []) {
            return [];
        }

        $result = [];
        foreach ($uniqueHostIds as $hostId) {
            $result[$hostId] = ['cpu' => 0, 'ram' => 0, 'disk' => 0];
        }

        $rows = DB::table('virtual_machines')
            ->whereIn('host_id', $uniqueHostIds)
            ->whereNotIn('status', ['failed', 'deleted'])
            ->groupBy('host_id')
            ->selectRaw('host_id, COALESCE(SUM(cpu_cores), 0) as cpu, COALESCE(SUM(ram_mb), 0) as ram, COALESCE(SUM(disk_gb), 0) as disk')
            ->get();

        foreach ($rows as $row) {
            /** @var object{host_id: int|string, cpu: int|float|string|null, ram: int|float|string|null, disk: int|float|string|null} $row */
            $hostId = Cast::int($row->host_id);
            $result[$hostId] = [
                'cpu' => Cast::int($row->cpu),
                'ram' => Cast::int($row->ram),
                'disk' => Cast::int($row->disk),
            ];
        }

        $reservationsByHost = $this->reservationService->getHostReservationsForHostIds($uniqueHostIds);
        foreach ($uniqueHostIds as $hostId) {
            $reservations = $reservationsByHost[$hostId] ?? ['cpu' => 0, 'ram' => 0, 'disk' => 0];
            $result[$hostId] = [
                'cpu' => $result[$hostId]['cpu'] + $reservations['cpu'],
                'ram' => $result[$hostId]['ram'] + $reservations['ram'],
                'disk' => $result[$hostId]['disk'] + $reservations['disk'],
            ];
        }

        return $result;
    }

    /**
     * Get maximum allocatable capacity per resource taking overcommit and reserves into account.
     *
     * @return array{cpu: float, ram: float, disk: float}
     */
    public function getMaxAllocatable(Host $host): array
    {
        $cpuOvercommit = $this->normalizeOvercommit($host->cpu_overcommit);
        $ramOvercommit = $this->normalizeOvercommit($host->ram_overcommit);
        $diskOvercommit = $this->normalizeOvercommit($host->disk_overcommit);

        $reserves = $this->getHostReserves();

        return [
            'cpu' => ((float) $host->total_cpu_cores * $cpuOvercommit) - (float) $reserves['cpu'],
            'ram' => ((float) $host->total_ram_mb * $ramOvercommit) - (float) $reserves['ram'],
            'disk' => ((float) $host->total_storage_gb * $diskOvercommit) - (float) $reserves['disk'],
        ];
    }

    /**
     * Get the maximum physical resources a single instance can request on this host
     * (physical total minus host reserve, without overcommit).
     *
     * @return array{cpu: int, ram: int, disk: int}
     */
    public function getSingleInstancePhysicalLimit(Host $host): array
    {
        if (! $this->hasKnownCapacity($host)) {
            return ['cpu' => 0, 'ram' => 0, 'disk' => 0];
        }

        $reserves = $this->getHostReserves();

        return [
            'cpu' => max(0, (int) $host->total_cpu_cores - $reserves['cpu']),
            'ram' => max(0, (int) $host->total_ram_mb - $reserves['ram']),
            'disk' => max(0, (int) $host->total_storage_gb - $reserves['disk']),
        ];
    }

    /**
     * Compute the maximum resources a single new instance can currently request on this host,
     * bounded by both remaining overcommitted pool capacity and physical single-node hardware limits.
     *
     * @param  array{cpu: int, ram: int, disk: int}|null  $committed
     * @return array{cpu: int, ram: int, disk: int}
     */
    public function getMaxAllocatableForSingleInstance(Host $host, ?array $committed = null): array
    {
        if (! $this->hasKnownCapacity($host)) {
            return ['cpu' => 0, 'ram' => 0, 'disk' => 0];
        }

        $committed ??= $this->getCommittedUsage($host);
        $maxPool = $this->getMaxAllocatable($host);
        $physicalLimit = $this->getSingleInstancePhysicalLimit($host);

        $freePoolCpu = max(0, (int) floor($maxPool['cpu'] - (float) $committed['cpu']));
        $freePoolRam = max(0, (int) floor($maxPool['ram'] - (float) $committed['ram']));
        $freePoolDisk = max(0, (int) floor($maxPool['disk'] - (float) $committed['disk']));

        return [
            'cpu' => min($physicalLimit['cpu'], $freePoolCpu),
            'ram' => min($physicalLimit['ram'], $freePoolRam),
            'disk' => min($physicalLimit['disk'], $freePoolDisk),
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

        $physicalLimit = $this->getSingleInstancePhysicalLimit($host);
        if ($requestedCpu > $physicalLimit['cpu'] || $requestedRam > $physicalLimit['ram'] || $requestedDisk > $physicalLimit['disk']) {
            return false;
        }

        $committed = $this->getCommittedUsage($host, $lock);
        $max = $this->getMaxAllocatable($host);

        $fitsCpu = ($committed['cpu'] + $requestedCpu) <= $max['cpu'];
        $fitsRam = ($committed['ram'] + $requestedRam) <= $max['ram'];
        $fitsDisk = ($committed['disk'] + $requestedDisk) <= $max['disk'];

        return $fitsCpu && $fitsRam && $fitsDisk;
    }

    private function normalizeOvercommit(mixed $value): float
    {
        $overcommit = Cast::float($value, 1.0);

        return $overcommit > 0.0 ? $overcommit : 1.0;
    }

    /**
     * @return array{cpu: int, ram: int, disk: int}
     */
    private function getHostReserves(): array
    {
        return [
            'cpu' => Cast::int(config('provisioning.host_cpu_reserve_cores', 0), 0),
            'ram' => Cast::int(config('provisioning.host_ram_reserve_mb', 1024), 1024),
            'disk' => Cast::int(config('provisioning.host_disk_reserve_gb', 0), 0),
        ];
    }

    /**
     * Evaluate whether a host can accommodate a proposed VM resource change.
     *
     * Skips capacity checks when no resource (CPU, RAM, disk) increases relative to the VM's current allocation.
     */
    public function checkChange(
        Host $host,
        VirtualMachine $vm,
        InstanceResourceSpec $target,
        ?int $excludeChangeId = null,
        bool $lock = false,
    ): CapacityCheckResult {
        $vmCpu = (int) $vm->cpu_cores;
        $vmRam = (int) $vm->ram_mb;
        $vmDisk = (int) $vm->disk_gb;

        if ($target->cpuCores <= $vmCpu && $target->ramMb <= $vmRam && $target->diskGb <= $vmDisk) {
            return new CapacityCheckResult(allowed: true);
        }

        if (! $this->hasKnownCapacity($host)) {
            return new CapacityCheckResult(
                allowed: false,
                reasonCode: 'HOST_CAPACITY_EXCEEDED',
                message: Cast::string(__('messages.host.capacity_unknown')),
            );
        }

        $physicalLimit = $this->getSingleInstancePhysicalLimit($host);
        if (
            $target->cpuCores > $physicalLimit['cpu']
            || $target->ramMb > $physicalLimit['ram']
            || $target->diskGb > $physicalLimit['disk']
        ) {
            return new CapacityCheckResult(
                allowed: false,
                reasonCode: 'HOST_CAPACITY_EXCEEDED',
                message: Cast::string(__('messages.host.insufficient_capacity')),
            );
        }

        $committed = $this->getCommittedUsage($host, $lock, $excludeChangeId);
        $max = $this->getMaxAllocatable($host);

        $isCountedInCommitted = ! in_array($vm->status->getValue(), ['failed', 'deleted'], true);
        $currentVmCpu = $isCountedInCommitted ? $vmCpu : 0;
        $currentVmRam = $isCountedInCommitted ? $vmRam : 0;
        $currentVmDisk = $isCountedInCommitted ? $vmDisk : 0;

        $afterCpu = $committed['cpu'] - $currentVmCpu + $target->cpuCores;
        $afterRam = $committed['ram'] - $currentVmRam + $target->ramMb;
        $afterDisk = $committed['disk'] - $currentVmDisk + $target->diskGb;

        if ($afterCpu > $max['cpu'] || $afterRam > $max['ram'] || $afterDisk > $max['disk']) {
            return new CapacityCheckResult(
                allowed: false,
                reasonCode: 'HOST_CAPACITY_EXCEEDED',
                message: Cast::string(__('messages.host.insufficient_capacity')),
            );
        }

        return new CapacityCheckResult(allowed: true);
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
