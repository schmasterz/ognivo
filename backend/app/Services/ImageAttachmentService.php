<?php

declare(strict_types=1);

namespace App\Services;

use App\DTO\Cast;
use App\DTO\ImageSelectionDTO;
use App\Models\Host;
use App\Models\Image;
use App\Models\VirtualMachine;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Service for attaching and detaching OS images to compute node hosts.
 */
final class ImageAttachmentService
{
    /**
     * Apply the desired image selection to the host within a database transaction.
     *
     * @return array{attached: int, detached: int, attached_ids: list<int>, detached_ids: list<int>}
     */
    public function apply(Host $host, ImageSelectionDTO $selection): array
    {
        return DB::transaction(fn (): array => $this->executeSelection($host, $selection));
    }

    /**
     * Preview the image changes and compute impact on active instances without modifying state.
     *
     * @return array{attached: int, detached: int, total_instances: int, images: list<array{id: int, name: string, instance_count: int}>}
     */
    public function preview(Host $host, ImageSelectionDTO $selection): array
    {
        DB::beginTransaction();

        try {
            $result = $this->executeSelection($host, $selection);
        } finally {
            DB::rollBack();
        }

        $detachedIds = $result['detached_ids'];
        $impactedImages = [];
        $totalInstances = 0;

        if (! empty($detachedIds)) {
            foreach (array_chunk($detachedIds, 500) as $chunk) {
                $query = DB::table('virtual_machines')
                    ->join('images', 'images.name', '=', 'virtual_machines.os_image')
                    ->where('virtual_machines.host_id', $host->id)
                    ->where('virtual_machines.status', '<>', 'deleted')
                    ->whereIn('images.id', $chunk);

                if (in_array(SoftDeletes::class, class_uses_recursive(VirtualMachine::class), true)) {
                    $query->whereNull('virtual_machines.deleted_at');
                }

                $rows = $query->groupBy('images.id', 'images.name')
                    ->havingRaw('COUNT(virtual_machines.id) > 0')
                    ->select([
                        'images.id',
                        'images.name',
                        DB::raw('COUNT(virtual_machines.id) AS instance_count'),
                    ])
                    ->get();

                foreach ($rows as $row) {
                    /** @var object{id: int|string, name: string, instance_count: int|float|string|null} $row */
                    $count = Cast::int($row->instance_count);
                    $totalInstances += $count;
                    $impactedImages[] = [
                        'id' => Cast::int($row->id),
                        'name' => Cast::string($row->name),
                        'instance_count' => $count,
                    ];
                }
            }
        }

        usort($impactedImages, fn (array $a, array $b): int => $b['instance_count'] <=> $a['instance_count'] ?: $a['id'] <=> $b['id']);

        /** @var list<array{id: int, name: string, instance_count: int}> $limitedImages */
        $limitedImages = array_slice($impactedImages, 0, 50);

        return [
            'attached' => $result['attached'],
            'detached' => $result['detached'],
            'total_instances' => $totalInstances,
            'images' => $limitedImages,
        ];
    }

    /**
     * Attach an image to all hosts configured with auto_attach_images = true.
     *
     * @return int Number of host_image records attached.
     */
    public function attachToAutoAttachHosts(Image $image): int
    {
        $now = now()->toDateTimeString();

        $subQuery = DB::table('hosts')
            ->where('auto_attach_images', true)
            ->selectRaw('hosts.id as host_id, ? as image_id, ? as created_at, ? as updated_at', [
                $image->id,
                $now,
                $now,
            ]);

        return (int) DB::table('host_image')->insertOrIgnoreUsing(
            ['host_id', 'image_id', 'created_at', 'updated_at'],
            $subQuery
        );
    }

    /**
     * Execute set-based operations for the specified selection.
     *
     * @return array{attached: int, detached: int, attached_ids: list<int>, detached_ids: list<int>}
     */
    private function executeSelection(Host $host, ImageSelectionDTO $selection): array
    {
        /** @var list<int> $beforeIds */
        $beforeIds = DB::table('host_image')
            ->where('host_id', $host->id)
            ->pluck('image_id')
            ->map(fn (mixed $id): int => Cast::int($id))
            ->all();

        if (! $selection->isNoop()) {
            $now = now()->toDateTimeString();

            match ($selection->mode) {
                ImageSelectionDTO::MODE_ALL => $this->applyAll($host, $now),
                ImageSelectionDTO::MODE_SELECTED => $this->applySelected($host, $selection->ids, $now),
                ImageSelectionDTO::MODE_FILTER => $this->applyFilterMode($host, $selection->filter, $selection->excludeIds, $now),
                ImageSelectionDTO::MODE_DELTA => $this->applyDelta($host, $selection->filterOps, $selection->attachIds, $selection->detachIds, $now),
            };
        }

        /** @var list<int> $afterIds */
        $afterIds = DB::table('host_image')
            ->where('host_id', $host->id)
            ->pluck('image_id')
            ->map(fn (mixed $id): int => Cast::int($id))
            ->all();

        /** @var list<int> $attachedIds */
        $attachedIds = array_values(array_map(fn (int $id): int => $id, array_diff($afterIds, $beforeIds)));
        /** @var list<int> $detachedIds */
        $detachedIds = array_values(array_map(fn (int $id): int => $id, array_diff($beforeIds, $afterIds)));

        return [
            'attached' => count($attachedIds),
            'detached' => count($detachedIds),
            'attached_ids' => $attachedIds,
            'detached_ids' => $detachedIds,
        ];
    }

    /**
     * Attach all catalog images to the host.
     */
    private function applyAll(Host $host, string $now): void
    {
        $subQuery = DB::table('images')
            ->selectRaw('? as host_id, images.id as image_id, ? as created_at, ? as updated_at', [$host->id, $now, $now]);

        DB::table('host_image')->insertOrIgnoreUsing(
            ['host_id', 'image_id', 'created_at', 'updated_at'],
            $subQuery
        );
    }

    /**
     * Attach selected image IDs and detach any others.
     *
     * @param  list<int>  $ids
     */
    private function applySelected(Host $host, array $ids, string $now): void
    {
        DB::table('host_image')
            ->where('host_id', $host->id)
            ->whereNotIn('image_id', $ids)
            ->delete();

        $subQuery = DB::table('images')
            ->whereIn('images.id', $ids)
            ->selectRaw('? as host_id, images.id as image_id, ? as created_at, ? as updated_at', [$host->id, $now, $now]);

        DB::table('host_image')->insertOrIgnoreUsing(
            ['host_id', 'image_id', 'created_at', 'updated_at'],
            $subQuery
        );
    }

    /**
     * Apply filter criteria with optional exclusions.
     *
     * @param  array<string, mixed>  $filter
     * @param  list<int>  $excludeIds
     */
    private function applyFilterMode(Host $host, array $filter, array $excludeIds, string $now): void
    {
        $deleteSubQuery = $this->applyFilter(DB::table('images'), $filter);
        if (! empty($excludeIds)) {
            $deleteSubQuery->whereNotIn('images.id', $excludeIds);
        }

        DB::table('host_image')
            ->where('host_id', $host->id)
            ->whereNotIn('image_id', $deleteSubQuery->select('images.id'))
            ->delete();

        $insertSubQuery = $this->applyFilter(DB::table('images'), $filter);
        if (! empty($excludeIds)) {
            $insertSubQuery->whereNotIn('images.id', $excludeIds);
        }
        $insertSubQuery->selectRaw('? as host_id, images.id as image_id, ? as created_at, ? as updated_at', [$host->id, $now, $now]);

        DB::table('host_image')->insertOrIgnoreUsing(
            ['host_id', 'image_id', 'created_at', 'updated_at'],
            $insertSubQuery
        );
    }

    /**
     * Apply sequential delta operations: filter ops in order, then attach IDs, then detach IDs.
     *
     * @param  list<array{action: 'attach'|'detach', filter: array<string, mixed>}>  $filterOps
     * @param  list<int>  $attachIds
     * @param  list<int>  $detachIds
     */
    private function applyDelta(Host $host, array $filterOps, array $attachIds, array $detachIds, string $now): void
    {
        foreach ($filterOps as $op) {
            if ($op['action'] === 'attach') {
                $attachSubQuery = $this->applyFilter(DB::table('images'), $op['filter'])
                    ->selectRaw('? as host_id, images.id as image_id, ? as created_at, ? as updated_at', [$host->id, $now, $now]);

                DB::table('host_image')->insertOrIgnoreUsing(
                    ['host_id', 'image_id', 'created_at', 'updated_at'],
                    $attachSubQuery
                );
            } elseif ($op['action'] === 'detach') {
                $detachSubQuery = $this->applyFilter(DB::table('images'), $op['filter'])
                    ->select('images.id');

                DB::table('host_image')
                    ->where('host_id', $host->id)
                    ->whereIn('image_id', $detachSubQuery)
                    ->delete();
            }
        }

        if (! empty($attachIds)) {
            foreach (array_chunk($attachIds, 500) as $chunk) {
                $attachSubQuery = DB::table('images')
                    ->whereIn('images.id', $chunk)
                    ->selectRaw('? as host_id, images.id as image_id, ? as created_at, ? as updated_at', [$host->id, $now, $now]);

                DB::table('host_image')->insertOrIgnoreUsing(
                    ['host_id', 'image_id', 'created_at', 'updated_at'],
                    $attachSubQuery
                );
            }
        }

        if (! empty($detachIds)) {
            foreach (array_chunk($detachIds, 500) as $chunk) {
                DB::table('host_image')
                    ->where('host_id', $host->id)
                    ->whereIn('image_id', $chunk)
                    ->delete();
            }
        }
    }

    /**
     * Apply filter criteria to the images query according to the whitelist.
     *
     * @param  array<string, mixed>  $filter
     */
    private function applyFilter(Builder $query, array $filter): Builder
    {
        foreach (array_keys($filter) as $key) {
            if (! in_array($key, ImageSelectionDTO::FILTER_KEYS, true)) {
                throw new InvalidArgumentException("Unknown filter key: {$key}");
            }
        }

        if (isset($filter['search']) && is_string($filter['search']) && $filter['search'] !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filter['search']);
            $pattern = "%{$escaped}%";

            $connection = $query->getConnection();
            $isSqlite = $connection instanceof Connection && $connection->getDriverName() === 'sqlite';

            if ($isSqlite) {
                $query->where(function (Builder $sub) use ($pattern): void {
                    $sub->whereRaw("images.name LIKE ? ESCAPE '\\'", [$pattern])
                        ->orWhereRaw("images.source LIKE ? ESCAPE '\\'", [$pattern]);
                });
            } else {
                $query->where(function (Builder $sub) use ($pattern): void {
                    $sub->where('images.name', 'like', $pattern)
                        ->orWhere('images.source', 'like', $pattern);
                });
            }
        }

        if (isset($filter['os_type']) && is_string($filter['os_type']) && $filter['os_type'] !== '') {
            $query->where('images.os_type', $filter['os_type']);
        }

        if (isset($filter['protocol']) && is_string($filter['protocol']) && $filter['protocol'] !== '') {
            $query->where('images.protocol', $filter['protocol']);
        }

        if (array_key_exists('enabled', $filter) && $filter['enabled'] !== null) {
            $query->where('images.enabled', (bool) $filter['enabled']);
        }

        return $query;
    }
}
