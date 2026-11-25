<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use App\DTO\Cast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates closed VM metric buckets into 5-minute and 1-hour rollup tiers.
 */
final class VmMetricsRollupService
{
    private const string CACHE_KEY_LAST_5M = 'metrics:rollup:last_5m_ts';

    private const string CACHE_KEY_LAST_1H = 'metrics:rollup:last_1h_ts';

    private const int UPSERT_CHUNK_SIZE = 500;

    /**
     * Mapping of raw sample columns to rollup avg/min/max column names.
     *
     * @var list<array{raw: string, avg: string, min: string, max: string}>
     */
    private const array METRIC_COLUMNS = [
        ['raw' => 'cpu_usage_bp', 'avg' => 'cpu_avg_bp', 'min' => 'cpu_min_bp', 'max' => 'cpu_max_bp'],
        ['raw' => 'ram_used_bytes', 'avg' => 'ram_avg_bytes', 'min' => 'ram_min_bytes', 'max' => 'ram_max_bytes'],
        ['raw' => 'disk_used_bytes', 'avg' => 'disk_avg_bytes', 'min' => 'disk_min_bytes', 'max' => 'disk_max_bytes'],
        ['raw' => 'disk_read_bps', 'avg' => 'disk_read_avg_bps', 'min' => 'disk_read_min_bps', 'max' => 'disk_read_max_bps'],
        ['raw' => 'disk_write_bps', 'avg' => 'disk_write_avg_bps', 'min' => 'disk_write_min_bps', 'max' => 'disk_write_max_bps'],
        ['raw' => 'net_rx_bps', 'avg' => 'net_rx_avg_bps', 'min' => 'net_rx_min_bps', 'max' => 'net_rx_max_bps'],
        ['raw' => 'net_tx_bps', 'avg' => 'net_tx_avg_bps', 'min' => 'net_tx_min_bps', 'max' => 'net_tx_max_bps'],
    ];

    public function __construct(
        private readonly ?VmMetricsConfigService $config = null,
    ) {}

    private function config(): VmMetricsConfigService
    {
        return $this->config ?? app(VmMetricsConfigService::class);
    }

    /**
     * Run both 5-minute and 1-hour metric rollups for closed buckets.
     *
     * Runs unconditionally even when metrics collection is disabled.
     *
     * @return array{rolled_5m: int, rolled_1h: int}
     */
    public function rollup(?int $nowEpoch = null): array
    {
        $now = $nowEpoch ?? time();

        return [
            'rolled_5m' => $this->rollupFiveMinuteBuckets($now),
            'rolled_1h' => $this->rollupOneHourBuckets($now),
        ];
    }

    /**
     * Aggregate closed 5-minute buckets from raw samples into vm_metric_rollups_5m.
     */
    public function rollupFiveMinuteBuckets(?int $nowEpoch = null): int
    {
        $now = $nowEpoch ?? time();
        $closedEnd5m = intdiv($now, 300) * 300;
        $defaultLookback = $now - (max(12, $this->config()->rawRetentionHours()) * 3600);
        $start5m = $this->resolveRollupStart(
            self::CACHE_KEY_LAST_5M,
            'vm_metric_rollups_5m',
            300,
            $closedEnd5m,
            $defaultLookback,
        );

        if ($start5m >= $closedEnd5m) {
            return 0;
        }

        $chunkSizeSeconds = 3600; // Process at most 1 hour (12 buckets) per chunk
        $totalRecords = 0;

        for ($chunkStart = $start5m; $chunkStart < $closedEnd5m; $chunkStart += $chunkSizeSeconds) {
            $chunkEnd = min($closedEnd5m, $chunkStart + $chunkSizeSeconds);

            $rows = DB::table('vm_metric_samples')
                ->where('bucket_ts', '>=', $chunkStart)
                ->where('bucket_ts', '<', $chunkEnd)
                ->orderBy('virtual_machine_id')
                ->orderBy('bucket_ts')
                ->get();

            if ($rows->isEmpty()) {
                continue;
            }

            /** @var array<int, array<int, list<array<string, mixed>>>> $groups */
            $groups = [];
            foreach ($rows as $row) {
                $attrs = get_object_vars($row);
                $vmId = Cast::int($attrs['virtual_machine_id'] ?? 0);
                $bucketTs = Cast::int($attrs['bucket_ts'] ?? 0);
                $rollupTs = intdiv($bucketTs, 300) * 300;

                $groups[$vmId][$rollupTs][] = $attrs;
            }

            /** @var list<array<string, int|null>> $records */
            $records = [];
            foreach ($groups as $vmId => $buckets) {
                foreach ($buckets as $rollupTs => $groupRows) {
                    $availMask = 0;
                    foreach ($groupRows as $groupRow) {
                        $availMask |= Cast::int($groupRow['avail_mask'] ?? 0);
                    }

                    /** @var array<string, int|null> $record */
                    $record = [
                        'virtual_machine_id' => $vmId,
                        'bucket_ts' => $rollupTs,
                        'sample_count' => count($groupRows),
                        'avail_mask' => $availMask,
                    ];

                    foreach (self::METRIC_COLUMNS as $col) {
                        $vals = [];
                        foreach ($groupRows as $groupRow) {
                            $val = Cast::intOrNull($groupRow[$col['raw']] ?? null);
                            if ($val !== null) {
                                $vals[] = $val;
                            }
                        }

                        if ($vals !== []) {
                            $record[$col['avg']] = (int) round(array_sum($vals) / count($vals));
                            $record[$col['min']] = min($vals);
                            $record[$col['max']] = max($vals);
                        } else {
                            $record[$col['avg']] = null;
                            $record[$col['min']] = null;
                            $record[$col['max']] = null;
                        }
                    }

                    $records[] = $record;
                }
            }

            $this->upsertRollupRecords('vm_metric_rollups_5m', $records);
            $totalRecords += count($records);
        }

        Cache::put(self::CACHE_KEY_LAST_5M, $closedEnd5m, 86400 * 7);

        return $totalRecords;
    }

    /**
     * Aggregate closed 1-hour buckets from 5-minute rollups into vm_metric_rollups_1h.
     */
    public function rollupOneHourBuckets(?int $nowEpoch = null): int
    {
        $now = $nowEpoch ?? time();
        $closedEnd1h = intdiv($now, 3600) * 3600;
        $defaultLookback = $now - (max(8, $this->config()->rollup5mRetentionDays()) * 86400);
        $start1h = $this->resolveRollupStart(
            self::CACHE_KEY_LAST_1H,
            'vm_metric_rollups_1h',
            3600,
            $closedEnd1h,
            $defaultLookback,
        );

        if ($start1h >= $closedEnd1h) {
            return 0;
        }

        $chunkSizeSeconds = 86400; // Process at most 24 hours per chunk
        $totalRecords = 0;

        for ($chunkStart = $start1h; $chunkStart < $closedEnd1h; $chunkStart += $chunkSizeSeconds) {
            $chunkEnd = min($closedEnd1h, $chunkStart + $chunkSizeSeconds);

            $rows = DB::table('vm_metric_rollups_5m')
                ->where('bucket_ts', '>=', $chunkStart)
                ->where('bucket_ts', '<', $chunkEnd)
                ->orderBy('virtual_machine_id')
                ->orderBy('bucket_ts')
                ->get();

            if ($rows->isEmpty()) {
                continue;
            }

            /** @var array<int, array<int, list<array<string, mixed>>>> $groups */
            $groups = [];
            foreach ($rows as $row) {
                $attrs = get_object_vars($row);
                $vmId = Cast::int($attrs['virtual_machine_id'] ?? 0);
                $bucketTs = Cast::int($attrs['bucket_ts'] ?? 0);
                $rollupTs = intdiv($bucketTs, 3600) * 3600;

                $groups[$vmId][$rollupTs][] = $attrs;
            }

            /** @var list<array<string, int|null>> $records */
            $records = [];
            foreach ($groups as $vmId => $buckets) {
                foreach ($buckets as $rollupTs => $groupRows) {
                    $sampleCount = 0;
                    $availMask = 0;

                    foreach ($groupRows as $groupRow) {
                        $sampleCount += Cast::int($groupRow['sample_count'] ?? 0);
                        $availMask |= Cast::int($groupRow['avail_mask'] ?? 0);
                    }

                    /** @var array<string, int|null> $record */
                    $record = [
                        'virtual_machine_id' => $vmId,
                        'bucket_ts' => $rollupTs,
                        'sample_count' => $sampleCount,
                        'avail_mask' => $availMask,
                    ];

                    foreach (self::METRIC_COLUMNS as $col) {
                        $weightedSum = 0;
                        $weightCount = 0;
                        $minVal = null;
                        $maxVal = null;

                        foreach ($groupRows as $groupRow) {
                            $weight = max(1, Cast::int($groupRow['sample_count'] ?? 1));
                            $avg = Cast::intOrNull($groupRow[$col['avg']] ?? null);
                            if ($avg !== null) {
                                $weightedSum += $avg * $weight;
                                $weightCount += $weight;
                            }

                            $min = Cast::intOrNull($groupRow[$col['min']] ?? null);
                            if ($min !== null) {
                                $minVal = $minVal === null ? $min : min($minVal, $min);
                            }

                            $max = Cast::intOrNull($groupRow[$col['max']] ?? null);
                            if ($max !== null) {
                                $maxVal = $maxVal === null ? $max : max($maxVal, $max);
                            }
                        }

                        $record[$col['avg']] = $weightCount > 0 ? (int) round($weightedSum / $weightCount) : null;
                        $record[$col['min']] = $minVal;
                        $record[$col['max']] = $maxVal;
                    }

                    $records[] = $record;
                }
            }

            $this->upsertRollupRecords('vm_metric_rollups_1h', $records);
            $totalRecords += count($records);
        }

        Cache::put(self::CACHE_KEY_LAST_1H, $closedEnd1h, 86400 * 35);

        return $totalRecords;
    }

    /**
     * Resolve the rollup start timestamp using cached cursor, DB max bucket fallback, or retention lookback.
     */
    private function resolveRollupStart(
        string $cacheKey,
        string $table,
        int $bucketSeconds,
        int $closedEnd,
        int $defaultLookback,
    ): int {
        /** @var mixed $cachedLast */
        $cachedLast = Cache::get($cacheKey);
        if (is_numeric($cachedLast)) {
            return min($closedEnd, max(0, (int) $cachedLast - $bucketSeconds));
        }

        /** @var mixed $maxDbBucket */
        $maxDbBucket = DB::table($table)->max('bucket_ts');
        if (is_numeric($maxDbBucket) && (int) $maxDbBucket > 0) {
            return min($closedEnd, max(0, (int) $maxDbBucket - $bucketSeconds));
        }

        return max(0, intdiv($defaultLookback, $bucketSeconds) * $bucketSeconds);
    }

    /**
     * Upsert aggregated rollup records into the target rollup table in chunks.
     *
     * @param  list<array<string, int|null>>  $records
     */
    private function upsertRollupRecords(string $table, array $records): void
    {
        if ($records === []) {
            return;
        }

        $updateColumns = $this->upsertUpdateColumns();
        foreach (array_chunk($records, self::UPSERT_CHUNK_SIZE) as $chunk) {
            DB::table($table)->upsert(
                $chunk,
                ['virtual_machine_id', 'bucket_ts'],
                $updateColumns,
            );
        }
    }

    /**
     * Return the list of non-key columns updated during rollup upserts.
     *
     * @return list<string>
     */
    private function upsertUpdateColumns(): array
    {
        $columns = ['sample_count', 'avail_mask'];
        foreach (self::METRIC_COLUMNS as $col) {
            $columns[] = $col['avg'];
            $columns[] = $col['min'];
            $columns[] = $col['max'];
        }

        return $columns;
    }
}
