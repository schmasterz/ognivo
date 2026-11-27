<?php

declare(strict_types=1);

namespace App\Services\Vps;

use App\DTO\Cast;
use App\States\VpsPlanChange\VpsPlanChangeState;
use Illuminate\Support\Facades\DB;

/**
 * Computes pending CPU, RAM, and disk reservations held by active VPS plan changes.
 */
final class ResourceReservationService
{
    /**
     * Sum positive resource deltas across all active plan changes belonging to a user.
     *
     * @return array{cpu: int, ram: int, disk: int}
     */
    public function getUserReservations(int $userId, ?int $excludeChangeId = null, bool $lock = false): array
    {
        $query = DB::table('vps_plan_changes')
            ->where('user_id', $userId)
            ->whereIn('status', VpsPlanChangeState::activeValues())
            ->select(['old_resources', 'new_resources']);

        if ($excludeChangeId !== null) {
            $query->where('id', '!=', $excludeChangeId);
        }

        if ($lock) {
            $query->lockForUpdate();
        }

        $totals = ['cpu' => 0, 'ram' => 0, 'disk' => 0];

        foreach ($query->get() as $row) {
            /** @var object{old_resources: mixed, new_resources: mixed} $row */
            $this->accumulateRowDelta($totals, $row->old_resources, $row->new_resources);
        }

        return $totals;
    }

    /**
     * Sum positive resource deltas across all active plan changes on a specific compute host.
     *
     * @return array{cpu: int, ram: int, disk: int}
     */
    public function getHostReservations(int $hostId, ?int $excludeChangeId = null, bool $lock = false): array
    {
        $query = DB::table('vps_plan_changes')
            ->join('virtual_machines', 'virtual_machines.id', '=', 'vps_plan_changes.virtual_machine_id')
            ->where('virtual_machines.host_id', $hostId)
            ->whereIn('vps_plan_changes.status', VpsPlanChangeState::activeValues())
            ->select(['vps_plan_changes.old_resources', 'vps_plan_changes.new_resources']);

        if ($excludeChangeId !== null) {
            $query->where('vps_plan_changes.id', '!=', $excludeChangeId);
        }

        if ($lock) {
            $query->lockForUpdate();
        }

        $totals = ['cpu' => 0, 'ram' => 0, 'disk' => 0];

        foreach ($query->get() as $row) {
            /** @var object{old_resources: mixed, new_resources: mixed} $row */
            $this->accumulateRowDelta($totals, $row->old_resources, $row->new_resources);
        }

        return $totals;
    }

    /**
     * Batch-compute positive resource deltas for multiple host IDs in a single query.
     *
     * @param  array<int>  $hostIds
     * @return array<int, array{cpu: int, ram: int, disk: int}>
     */
    public function getHostReservationsForHostIds(array $hostIds): array
    {
        $uniqueHostIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => Cast::int($id), $hostIds),
            static fn (int $id): bool => $id > 0
        )));

        if ($uniqueHostIds === []) {
            return [];
        }

        /** @var array<int, array{cpu: int, ram: int, disk: int}> $result */
        $result = [];
        foreach ($uniqueHostIds as $hostId) {
            $result[$hostId] = ['cpu' => 0, 'ram' => 0, 'disk' => 0];
        }

        $rows = DB::table('vps_plan_changes')
            ->join('virtual_machines', 'virtual_machines.id', '=', 'vps_plan_changes.virtual_machine_id')
            ->whereIn('virtual_machines.host_id', $uniqueHostIds)
            ->whereIn('vps_plan_changes.status', VpsPlanChangeState::activeValues())
            ->select([
                'virtual_machines.host_id',
                'vps_plan_changes.old_resources',
                'vps_plan_changes.new_resources',
            ])
            ->get();

        foreach ($rows as $row) {
            /** @var object{host_id: int|string, old_resources: mixed, new_resources: mixed} $row */
            $hostId = Cast::int($row->host_id);
            if (! isset($result[$hostId])) {
                continue;
            }

            $this->accumulateRowDelta($result[$hostId], $row->old_resources, $row->new_resources);
        }

        return $result;
    }

    /**
     * @param  array{cpu: int, ram: int, disk: int}  $totals
     */
    private function accumulateRowDelta(array &$totals, mixed $rawOld, mixed $rawNew): void
    {
        $old = $this->decodeResources($rawOld);
        $new = $this->decodeResources($rawNew);

        $totals['cpu'] += max(0, Cast::int($new['cpu_cores'] ?? 0) - Cast::int($old['cpu_cores'] ?? 0));
        $totals['ram'] += max(0, Cast::int($new['ram_mb'] ?? 0) - Cast::int($old['ram_mb'] ?? 0));
        $totals['disk'] += max(0, Cast::int($new['disk_gb'] ?? 0) - Cast::int($old['disk_gb'] ?? 0));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResources(mixed $raw): array
    {
        if (is_array($raw)) {
            /** @var array<string, mixed> $raw */
            return $raw;
        }

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                return $decoded;
            }
        }

        return [];
    }
}
