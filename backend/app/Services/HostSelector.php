<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Host;
use App\Models\Image;
use App\Models\IpPool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Service selecting the optimal compute host for VM provisioning based on health,
 * image availability, IP capacity, and committed resource load with overcommit factors.
 */
class HostSelector
{
    public function __construct(
        private readonly IpManagementService $ipService,
        private readonly HostCapacityGuard $capacityGuard = new HostCapacityGuard,
    ) {}

    /**
     * Select the optimal compute node given provisioning filters.
     *
     * @param array{
     *     os_image?: string|Image|null,
     *     image?: string|Image|null,
     *     instance_type?: string|null,
     *     cpu_cores?: int|null,
     *     ram_mb?: int|null,
     *     disk_gb?: int|null
     * } $filters
     */
    public function select(array $filters = []): ?Host
    {
        $query = Host::query()
            ->where('is_active', true)
            ->where('status', 'ok')
            ->whereNotIn('status', ['fingerprint_mismatch', 'not_trusted', 'unreachable'])
            ->where('capacity_source', '!=', 'unknown')
            ->where('total_cpu_cores', '>', 0)
            ->where('total_ram_mb', '>', 0)
            ->where('total_storage_gb', '>', 0);

        $isLocalOrTesting = app()->environment('local', 'testing')
            || in_array(config('app.env'), ['local', 'testing'], true);

        if (! $isLocalOrTesting) {
            $query->where('driver', '!=', 'fake');
        }

        $imageName = $filters['os_image'] ?? ($filters['image'] ?? null);
        if ($imageName instanceof Image) {
            $imageName = $imageName->name;
        }

        if (is_string($imageName) && $imageName !== '') {
            $image = Image::where('name', $imageName)->where('enabled', true)->first();
            if ($image === null) {
                return null;
            }

            $query->whereHas('images', function (Builder $q) use ($image): void {
                $q->where('images.id', $image->id)->where('images.enabled', true);
            });
        }

        /** @var Collection<int, Host> $candidateHosts */
        $candidateHosts = $query->get();

        if ($candidateHosts->isEmpty()) {
            return null;
        }

        $reqCpu = max(1, $filters['cpu_cores'] ?? 1);
        $reqRam = max(1, $filters['ram_mb'] ?? 1024);
        $reqDisk = max(1, $filters['disk_gb'] ?? 10);

        // Filter candidates that have at least one active pool with free addresses AND sufficient capacity
        $eligibleHosts = [];
        foreach ($candidateHosts as $host) {
            if ($this->hostHasFreeIpPool($host) && $this->capacityGuard->canFit($host, $reqCpu, $reqRam, $reqDisk)) {
                $eligibleHosts[] = $host;
            }
        }

        if (empty($eligibleHosts)) {
            return null;
        }

        return $this->pickLeastLoadedHost($eligibleHosts);
    }

    /**
     * Validate that an explicitly specified host meets all provisioning requirements.
     *
     * @param array{
     *     os_image?: string|Image|null,
     *     image?: string|Image|null,
     *     instance_type?: string|null,
     *     cpu_cores?: int|null,
     *     ram_mb?: int|null,
     *     disk_gb?: int|null
     * } $filters
     *
     * @throws ValidationException
     */
    public function validateHost(Host $host, array $filters = []): void
    {
        if (! $host->is_active) {
            throw ValidationException::withMessages([
                'host_id' => [__('messages.host.not_active_or_available')],
            ]);
        }

        if ($host->status !== 'ok' || in_array($host->status, ['fingerprint_mismatch', 'not_trusted', 'unreachable'], true)) {
            throw ValidationException::withMessages([
                'host_id' => ['The selected compute node is not healthy or available.'],
            ]);
        }

        $isLocalOrTesting = app()->environment('local', 'testing')
            || in_array(config('app.env'), ['local', 'testing'], true);

        if (! $isLocalOrTesting && $host->driver === 'fake') {
            throw ValidationException::withMessages([
                'host_id' => ['Fake driver compute nodes are not permitted in this environment.'],
            ]);
        }

        $imageName = $filters['os_image'] ?? ($filters['image'] ?? null);
        if ($imageName instanceof Image) {
            $imageName = $imageName->name;
        }

        if (is_string($imageName) && $imageName !== '') {
            $image = Image::where('name', $imageName)->where('enabled', true)->first();
            if ($image === null) {
                throw ValidationException::withMessages([
                    'os_image' => ['The selected image is not in the allowlist or is disabled.'],
                ]);
            }

            if (! $image->hosts()->where('hosts.id', $host->id)->exists()) {
                throw ValidationException::withMessages([
                    'os_image' => ['The selected image is not available on the chosen compute node.'],
                ]);
            }
        }

        $cpu = max(0, $filters['cpu_cores'] ?? 0);
        $ram = max(0, $filters['ram_mb'] ?? 0);
        $disk = max(0, $filters['disk_gb'] ?? 0);

        if ($cpu > 0 || $ram > 0 || $disk > 0) {
            $this->capacityGuard->check($host, $cpu, $ram, $disk, lock: false);
        } elseif (! $this->capacityGuard->hasKnownCapacity($host)) {
            throw ValidationException::withMessages([
                'host_id' => [__('messages.host.capacity_unknown')],
            ]);
        }
    }

    /**
     * Check if a host has an active IP pool with free addresses.
     */
    public function hostHasFreeIpPool(Host $host): bool
    {
        $activePools = IpPool::where('host_id', $host->id)
            ->where('active', true)
            ->orderBy('id', 'asc')
            ->get();

        if ($activePools->isEmpty()) {
            return false;
        }

        $this->ipService->attachUtilizationToPools($activePools);

        foreach ($activePools as $pool) {
            /** @var array{total: int, allocated: int, cooling: int, free: int, percent: float}|null $util */
            $util = $pool->utilization;
            if ($util !== null && $util['free'] > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Among candidate hosts, choose the least loaded one based on committed resources
     * divided by host totals (when available) with overcommit factors, tie-breaking by id.
     *
     * @param  list<Host>  $hosts
     */
    private function pickLeastLoadedHost(array $hosts): Host
    {
        $hostIds = array_map(fn (Host $h) => $h->id, $hosts);

        $usages = DB::table('virtual_machines')
            ->whereIn('host_id', $hostIds)
            ->whereNotIn('status', ['failed', 'deleted'])
            ->groupBy('host_id')
            ->selectRaw('host_id, COALESCE(SUM(cpu_cores), 0) as cpu, COALESCE(SUM(ram_mb), 0) as ram, COALESCE(SUM(disk_gb), 0) as disk')
            ->get()
            ->keyBy('host_id');

        $scored = [];
        foreach ($hosts as $host) {
            $usage = $usages->get($host->id);
            $committedCpu = (float) ($usage->cpu ?? 0);
            $committedRam = (float) ($usage->ram ?? 0);
            $committedDisk = (float) ($usage->disk ?? 0);

            $cpuOvercommit = (float) ($host->cpu_overcommit > 0 ? $host->cpu_overcommit : 1.0);
            $ramOvercommit = (float) ($host->ram_overcommit > 0 ? $host->ram_overcommit : 1.0);
            $diskOvercommit = (float) ($host->disk_overcommit > 0 ? $host->disk_overcommit : 1.0);

            $hasTotals = ($host->total_cpu_cores > 0 || $host->total_ram_mb > 0 || $host->total_storage_gb > 0);

            if ($hasTotals) {
                $cpuScore = $host->total_cpu_cores > 0
                    ? ($committedCpu / ((float) $host->total_cpu_cores * $cpuOvercommit))
                    : 0.0;
                $ramScore = $host->total_ram_mb > 0
                    ? ($committedRam / ((float) $host->total_ram_mb * $ramOvercommit))
                    : 0.0;
                $diskScore = $host->total_storage_gb > 0
                    ? ($committedDisk / ((float) $host->total_storage_gb * $diskOvercommit))
                    : 0.0;

                $load = ($cpuScore + $ramScore + $diskScore) / 3.0;
            } else {
                $load = (float) ($committedCpu + ($committedRam / 1024) + $committedDisk);
            }

            $scored[] = [
                'host' => $host,
                'load' => $load,
                'id' => $host->id,
            ];
        }

        usort($scored, function (array $a, array $b): int {
            if (abs($a['load'] - $b['load']) > 0.000001) {
                return $a['load'] <=> $b['load'];
            }

            return $a['id'] <=> $b['id'];
        });

        return $scored[0]['host'];
    }
}
