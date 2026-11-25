<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use App\DTO\Cast;
use App\Models\VirtualMachine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Queries multi-tier VM telemetry series with dynamic bucket widening,
 * freshness tail seam filling, capacity total resolution, and explicit gap/unavailability markers.
 */
final class VmMetricsQueryService
{
    /**
     * Metric column mapping across raw samples and rollup tables.
     *
     * @var array<string, array{raw: string, avg: string, min: string, max: string}>
     */
    private const array METRIC_COLUMNS = [
        'cpu' => ['raw' => 'cpu_usage_bp', 'avg' => 'cpu_avg_bp', 'min' => 'cpu_min_bp', 'max' => 'cpu_max_bp'],
        'ram' => ['raw' => 'ram_used_bytes', 'avg' => 'ram_avg_bytes', 'min' => 'ram_min_bytes', 'max' => 'ram_max_bytes'],
        'disk' => ['raw' => 'disk_used_bytes', 'avg' => 'disk_avg_bytes', 'min' => 'disk_min_bytes', 'max' => 'disk_max_bytes'],
        'disk_read' => ['raw' => 'disk_read_bps', 'avg' => 'disk_read_avg_bps', 'min' => 'disk_read_min_bps', 'max' => 'disk_read_max_bps'],
        'disk_write' => ['raw' => 'disk_write_bps', 'avg' => 'disk_write_avg_bps', 'min' => 'disk_write_min_bps', 'max' => 'disk_write_max_bps'],
        'net_rx' => ['raw' => 'net_rx_bps', 'avg' => 'net_rx_avg_bps', 'min' => 'net_rx_min_bps', 'max' => 'net_rx_max_bps'],
        'net_tx' => ['raw' => 'net_tx_bps', 'avg' => 'net_tx_avg_bps', 'min' => 'net_tx_min_bps', 'max' => 'net_tx_max_bps'],
    ];

    /**
     * Metric availability bitmask mapping for each internal metric key.
     *
     * @var array<string, int>
     */
    private const array SERIES_AVAIL_BITS = [
        'cpu' => VmMetricsCollectorService::BIT_CPU,
        'memory' => VmMetricsCollectorService::BIT_RAM,
        'disk_space' => VmMetricsCollectorService::BIT_DISK_SPACE,
        'disk_io' => VmMetricsCollectorService::BIT_DISK_IO,
        'network' => VmMetricsCollectorService::BIT_NETWORK,
    ];

    public function __construct(
        private readonly ?VmMetricsConfigService $config = null,
    ) {}

    /**
     * Query VM telemetry metrics for a given time range, requested metric groups, and point budget.
     *
     * @param  list<string>|null  $requestedMetrics
     * @return array{
     *     virtual_machine_id: int,
     *     instance_type: string,
     *     status: string,
     *     range: string,
     *     tier: string,
     *     bucket_width_seconds: int,
     *     refresh_interval_seconds: int,
     *     available_ranges: list<string>,
     *     last_sample_at: string|null,
     *     totals: array{
     *         cpu_cores: int,
     *         memory_total_bytes: int,
     *         disk_total_bytes: int
     *     },
     *     series: array<string, array<string, mixed>>
     * }
     */
    public function query(
        VirtualMachine $vm,
        string $range = '1h',
        ?array $requestedMetrics = null,
        int $maxPoints = 500,
        ?int $nowEpoch = null,
    ): array {
        $cfg = $this->config ?? app(VmMetricsConfigService::class);
        $plan = $cfg->resolveQueryPlan($range, $maxPoints);
        $metrics = $requestedMetrics !== null && $requestedMetrics !== []
            ? array_values(array_intersect(VmMetricsConfigService::SUPPORTED_METRICS, $requestedMetrics))
            : VmMetricsConfigService::SUPPORTED_METRICS;

        $now = $nowEpoch ?? time();
        $step = $plan['bucket_width_seconds'];
        $count = $plan['bucket_count'];
        $endTs = intdiv($now, $step) * $step;
        $startTs = $endTs - (($count - 1) * $step);
        $windowEndExclusive = $endTs + $step;

        $normalizedRows = $this->fetchTierRows($vm->id, $plan['tier'], $startTs, $windowEndExclusive);

        /** @var list<int> $gridTimestamps */
        $gridTimestamps = [];
        /** @var array<int, list<array{
         *     bucket_ts: int,
         *     sample_count: int,
         *     avail_mask: int,
         *     metrics: array<string, array{avg: int|null, min: int|null, max: int|null}>
         * }>> $gridRows
         */
        $gridRows = [];

        for ($i = 0; $i < $count; $i++) {
            $gridTs = $startTs + ($i * $step);
            $gridTimestamps[] = $gridTs;
            $gridRows[$gridTs] = [];
        }

        foreach ($normalizedRows as $row) {
            $rowTs = $row['bucket_ts'];
            if ($rowTs < $startTs || $rowTs >= $windowEndExclusive) {
                continue;
            }

            $gridTs = $startTs + (intdiv($rowTs - $startTs, $step) * $step);
            if (isset($gridRows[$gridTs])) {
                $gridRows[$gridTs][] = $row;
            }
        }

        /** @var array<int, array{
         *     has_rows: bool,
         *     avail_mask: int,
         *     metrics: array<string, array{avg: float|int|null, min: float|int|null, max: float|int|null}>
         * }> $buckets
         */
        $buckets = [];
        foreach ($gridTimestamps as $gridTs) {
            $buckets[$gridTs] = $this->aggregateGridBucket($gridRows[$gridTs]);
        }

        $series = $this->buildSeries($metrics, $gridTimestamps, $buckets);
        $totals = $this->resolveTotals($vm);
        $lastSampleAt = $this->resolveLastSampleAt($vm->id, $normalizedRows);

        return [
            'virtual_machine_id' => $vm->id,
            'instance_type' => $vm->instance_type,
            'status' => (string) $vm->status,
            'range' => $plan['range'],
            'tier' => $plan['tier'],
            'bucket_width_seconds' => $plan['bucket_width_seconds'],
            'refresh_interval_seconds' => $plan['refresh_interval_seconds'],
            'available_ranges' => $cfg->availableRanges(),
            'last_sample_at' => $lastSampleAt,
            'totals' => $totals,
            'series' => $series,
        ];
    }

    /**
     * Fetch normalized rows for the selected tier and fill freshness tail seams from finer tiers.
     *
     * @return list<array{
     *     bucket_ts: int,
     *     sample_count: int,
     *     avail_mask: int,
     *     metrics: array<string, array{avg: int|null, min: int|null, max: int|null}>
     * }>
     */
    private function fetchTierRows(int $vmId, string $tier, int $startTs, int $windowEndExclusive): array
    {
        if ($tier === 'rollup_5m') {
            $rows5m = $this->fetchRollupRows('vm_metric_rollups_5m', $vmId, $startTs, $windowEndExclusive);
            $max5mTs = $this->maxBucketTs($rows5m);
            $seamRawStart = $max5mTs !== null ? ($max5mTs + 300) : $startTs;
            $seamRawRows = $this->fetchRawRows($vmId, $seamRawStart, $windowEndExclusive);

            return [...$rows5m, ...$seamRawRows];
        }

        if ($tier === 'rollup_1h') {
            $rows1h = $this->fetchRollupRows('vm_metric_rollups_1h', $vmId, $startTs, $windowEndExclusive);
            $max1hTs = $this->maxBucketTs($rows1h);
            $seam5mStart = $max1hTs !== null ? ($max1hTs + 3600) : $startTs;
            $rows5m = $this->fetchRollupRows('vm_metric_rollups_5m', $vmId, $seam5mStart, $windowEndExclusive);
            $max5mTs = $this->maxBucketTs($rows5m);
            $seamRawStart = $max5mTs !== null ? ($max5mTs + 300) : $seam5mStart;
            $seamRawRows = $this->fetchRawRows($vmId, $seamRawStart, $windowEndExclusive);

            return [...$rows1h, ...$rows5m, ...$seamRawRows];
        }

        return $this->fetchRawRows($vmId, $startTs, $windowEndExclusive);
    }

    /**
     * Fetch and normalize raw sample rows from vm_metric_samples.
     *
     * @return list<array{
     *     bucket_ts: int,
     *     sample_count: int,
     *     avail_mask: int,
     *     metrics: array<string, array{avg: int|null, min: int|null, max: int|null}>
     * }>
     */
    private function fetchRawRows(int $vmId, int $fromTs, int $toExclusiveTs): array
    {
        if ($fromTs >= $toExclusiveTs) {
            return [];
        }

        $rows = DB::table('vm_metric_samples')
            ->where('virtual_machine_id', $vmId)
            ->where('bucket_ts', '>=', $fromTs)
            ->where('bucket_ts', '<', $toExclusiveTs)
            ->orderBy('bucket_ts')
            ->get();

        $normalized = [];
        foreach ($rows as $row) {
            $attrs = get_object_vars($row);
            $metrics = [];

            foreach (self::METRIC_COLUMNS as $key => $cols) {
                $val = Cast::intOrNull($attrs[$cols['raw']] ?? null);
                $metrics[$key] = [
                    'avg' => $val,
                    'min' => $val,
                    'max' => $val,
                ];
            }

            $normalized[] = [
                'bucket_ts' => Cast::int($attrs['bucket_ts'] ?? 0),
                'sample_count' => 1,
                'avail_mask' => Cast::int($attrs['avail_mask'] ?? 0),
                'metrics' => $metrics,
            ];
        }

        return $normalized;
    }

    /**
     * Fetch and normalize rollup rows from a rollup table.
     *
     * @return list<array{
     *     bucket_ts: int,
     *     sample_count: int,
     *     avail_mask: int,
     *     metrics: array<string, array{avg: int|null, min: int|null, max: int|null}>
     * }>
     */
    private function fetchRollupRows(string $table, int $vmId, int $fromTs, int $toExclusiveTs): array
    {
        if ($fromTs >= $toExclusiveTs) {
            return [];
        }

        $rows = DB::table($table)
            ->where('virtual_machine_id', $vmId)
            ->where('bucket_ts', '>=', $fromTs)
            ->where('bucket_ts', '<', $toExclusiveTs)
            ->orderBy('bucket_ts')
            ->get();

        $normalized = [];
        foreach ($rows as $row) {
            $attrs = get_object_vars($row);
            $metrics = [];

            foreach (self::METRIC_COLUMNS as $key => $cols) {
                $metrics[$key] = [
                    'avg' => Cast::intOrNull($attrs[$cols['avg']] ?? null),
                    'min' => Cast::intOrNull($attrs[$cols['min']] ?? null),
                    'max' => Cast::intOrNull($attrs[$cols['max']] ?? null),
                ];
            }

            $normalized[] = [
                'bucket_ts' => Cast::int($attrs['bucket_ts'] ?? 0),
                'sample_count' => max(1, Cast::int($attrs['sample_count'] ?? 1)),
                'avail_mask' => Cast::int($attrs['avail_mask'] ?? 0),
                'metrics' => $metrics,
            ];
        }

        return $normalized;
    }

    /**
     * Return the maximum bucket_ts across a list of normalized rows, or null if empty.
     *
     * @param  list<array{
     *     bucket_ts: int,
     *     sample_count: int,
     *     avail_mask: int,
     *     metrics: array<string, array{avg: int|null, min: int|null, max: int|null}>
     * }>  $rows
     */
    private function maxBucketTs(array $rows): ?int
    {
        $max = null;
        foreach ($rows as $row) {
            if ($max === null || $row['bucket_ts'] > $max) {
                $max = $row['bucket_ts'];
            }
        }

        return $max;
    }

    /**
     * Aggregate all normalized rows assigned to a single grid bucket.
     *
     * @param  list<array{
     *     bucket_ts: int,
     *     sample_count: int,
     *     avail_mask: int,
     *     metrics: array<string, array{avg: int|null, min: int|null, max: int|null}>
     * }>  $rows
     * @return array{
     *     has_rows: bool,
     *     avail_mask: int,
     *     metrics: array<string, array{avg: float|int|null, min: float|int|null, max: float|int|null}>
     * }
     */
    private function aggregateGridBucket(array $rows): array
    {
        if ($rows === []) {
            $emptyMetrics = [];
            foreach (array_keys(self::METRIC_COLUMNS) as $key) {
                $emptyMetrics[$key] = ['avg' => null, 'min' => null, 'max' => null];
            }

            return [
                'has_rows' => false,
                'avail_mask' => 0,
                'metrics' => $emptyMetrics,
            ];
        }

        $availMask = 0;
        foreach ($rows as $row) {
            $availMask |= $row['avail_mask'];
        }

        $aggregatedMetrics = [];
        foreach (array_keys(self::METRIC_COLUMNS) as $key) {
            $weightedSum = 0.0;
            $weightCount = 0;
            $minVal = null;
            $maxVal = null;

            foreach ($rows as $row) {
                $stat = $row['metrics'][$key];
                $weight = max(1, $row['sample_count']);

                if ($stat['avg'] !== null) {
                    $weightedSum += (float) $stat['avg'] * (float) $weight;
                    $weightCount += $weight;
                }

                if ($stat['min'] !== null) {
                    $minVal = $minVal === null ? $stat['min'] : min($minVal, $stat['min']);
                }

                if ($stat['max'] !== null) {
                    $maxVal = $maxVal === null ? $stat['max'] : max($maxVal, $stat['max']);
                }
            }

            if ($key === 'cpu') {
                $aggregatedMetrics[$key] = [
                    'avg' => $weightCount > 0 ? round(($weightedSum / (float) $weightCount) / 100.0, 2) : null,
                    'min' => $minVal !== null ? round((float) $minVal / 100.0, 2) : null,
                    'max' => $maxVal !== null ? round((float) $maxVal / 100.0, 2) : null,
                ];
            } else {
                $aggregatedMetrics[$key] = [
                    'avg' => $weightCount > 0 ? (int) round($weightedSum / (float) $weightCount) : null,
                    'min' => $minVal,
                    'max' => $maxVal,
                ];
            }
        }

        return [
            'has_rows' => true,
            'avail_mask' => $availMask,
            'metrics' => $aggregatedMetrics,
        ];
    }

    /**
     * Format requested series with availability metadata and explicit null/reason points.
     *
     * @param  list<string>  $metrics
     * @param  list<int>  $gridTimestamps
     * @param  array<int, array{
     *     has_rows: bool,
     *     avail_mask: int,
     *     metrics: array<string, array{avg: float|int|null, min: float|int|null, max: float|int|null}>
     * }>  $buckets
     * @return array<string, array<string, mixed>>
     */
    private function buildSeries(array $metrics, array $gridTimestamps, array $buckets): array
    {
        $series = [];

        foreach ($metrics as $metricGroup) {
            $bit = self::SERIES_AVAIL_BITS[$metricGroup] ?? 0;
            $available = $this->isSeriesAvailable($gridTimestamps, $buckets, $bit);
            $unavailableReason = $available ? null : 'guest_agent_not_running';

            if ($metricGroup === 'cpu') {
                $series['cpu'] = [
                    'unit' => 'percent',
                    'available' => $available,
                    'unavailable_reason' => $unavailableReason,
                    'points' => $this->formatMetricPoints($gridTimestamps, $buckets, 'cpu', $bit),
                ];
            } elseif ($metricGroup === 'memory') {
                $series['memory'] = [
                    'unit' => 'bytes',
                    'available' => $available,
                    'unavailable_reason' => $unavailableReason,
                    'points' => $this->formatMetricPoints($gridTimestamps, $buckets, 'ram', $bit),
                ];
            } elseif ($metricGroup === 'disk_space') {
                $series['disk_space'] = [
                    'unit' => 'bytes',
                    'available' => $available,
                    'unavailable_reason' => $unavailableReason,
                    'points' => $this->formatMetricPoints($gridTimestamps, $buckets, 'disk', $bit),
                ];
            } elseif ($metricGroup === 'disk_io') {
                $series['disk_io'] = [
                    'unit' => 'bytes_per_second',
                    'available' => $available,
                    'unavailable_reason' => $unavailableReason,
                    'read' => $this->formatMetricPoints($gridTimestamps, $buckets, 'disk_read', $bit),
                    'write' => $this->formatMetricPoints($gridTimestamps, $buckets, 'disk_write', $bit),
                ];
            } elseif ($metricGroup === 'network') {
                $series['network'] = [
                    'unit' => 'bytes_per_second',
                    'available' => $available,
                    'unavailable_reason' => $unavailableReason,
                    'rx' => $this->formatMetricPoints($gridTimestamps, $buckets, 'net_rx', $bit),
                    'tx' => $this->formatMetricPoints($gridTimestamps, $buckets, 'net_tx', $bit),
                ];
            }
        }

        return $series;
    }

    /**
     * Determine series-level availability across populated grid buckets.
     *
     * @param  list<int>  $gridTimestamps
     * @param  array<int, array{
     *     has_rows: bool,
     *     avail_mask: int,
     *     metrics: array<string, array{avg: float|int|null, min: float|int|null, max: float|int|null}>
     * }>  $buckets
     */
    private function isSeriesAvailable(array $gridTimestamps, array $buckets, int $bit): bool
    {
        $hasAnyRows = false;

        foreach ($gridTimestamps as $gridTs) {
            $bucket = $buckets[$gridTs];
            if (! $bucket['has_rows']) {
                continue;
            }

            $hasAnyRows = true;
            if (($bucket['avail_mask'] & $bit) !== 0) {
                return true;
            }
        }

        return ! $hasAnyRows;
    }

    /**
     * Build evenly spaced point entries for a single metric key.
     *
     * @param  list<int>  $gridTimestamps
     * @param  array<int, array{
     *     has_rows: bool,
     *     avail_mask: int,
     *     metrics: array<string, array{avg: float|int|null, min: float|int|null, max: float|int|null}>
     * }>  $buckets
     * @return list<array{
     *     ts: int,
     *     avg: float|int|null,
     *     min: float|int|null,
     *     max: float|int|null,
     *     reason: string|null
     * }>
     */
    private function formatMetricPoints(array $gridTimestamps, array $buckets, string $metricKey, int $bit): array
    {
        $points = [];

        foreach ($gridTimestamps as $gridTs) {
            $bucket = $buckets[$gridTs];

            if (! $bucket['has_rows']) {
                $points[] = [
                    'ts' => $gridTs,
                    'avg' => null,
                    'min' => null,
                    'max' => null,
                    'reason' => 'gap',
                ];

                continue;
            }

            if (($bucket['avail_mask'] & $bit) === 0) {
                $points[] = [
                    'ts' => $gridTs,
                    'avg' => null,
                    'min' => null,
                    'max' => null,
                    'reason' => 'unavailable',
                ];

                continue;
            }

            $stat = $bucket['metrics'][$metricKey];
            if ($stat['avg'] === null) {
                $points[] = [
                    'ts' => $gridTs,
                    'avg' => null,
                    'min' => null,
                    'max' => null,
                    'reason' => 'gap',
                ];

                continue;
            }

            $points[] = [
                'ts' => $gridTs,
                'avg' => $stat['avg'],
                'min' => $stat['min'],
                'max' => $stat['max'],
                'reason' => null,
            ];
        }

        return $points;
    }

    /**
     * Resolve latest capacity totals from vm_metric_totals with fallback to VirtualMachine attributes.
     *
     * @return array{
     *     cpu_cores: int,
     *     memory_total_bytes: int,
     *     disk_total_bytes: int
     * }
     */
    private function resolveTotals(VirtualMachine $vm): array
    {
        $latest = DB::table('vm_metric_totals')
            ->where('virtual_machine_id', $vm->id)
            ->orderByDesc('recorded_at_ts')
            ->first(['cpu_cores', 'ram_total_bytes', 'disk_total_bytes']);

        if ($latest !== null) {
            return [
                'cpu_cores' => max(1, Cast::int($latest->cpu_cores)),
                'memory_total_bytes' => Cast::int($latest->ram_total_bytes),
                'disk_total_bytes' => Cast::int($latest->disk_total_bytes),
            ];
        }

        return [
            'cpu_cores' => max(1, $vm->cpu_cores),
            'memory_total_bytes' => $vm->ram_mb * 1024 * 1024,
            'disk_total_bytes' => $vm->disk_gb * 1024 * 1024 * 1024,
        ];
    }

    /**
     * Resolve ISO-8601 UTC timestamp of the most recent telemetry sample for this VM.
     *
     * @param  list<array{
     *     bucket_ts: int,
     *     sample_count: int,
     *     avail_mask: int,
     *     metrics: array<string, array{avg: int|null, min: int|null, max: int|null}>
     * }>  $normalizedRows
     */
    private function resolveLastSampleAt(int $vmId, array $normalizedRows): ?string
    {
        /** @var mixed $rawMax */
        $rawMax = DB::table('vm_metric_samples')
            ->where('virtual_machine_id', $vmId)
            ->max('bucket_ts');

        $lastSampleTs = Cast::intOrNull($rawMax);
        $queryMaxTs = $this->maxBucketTs($normalizedRows);

        if ($lastSampleTs === null) {
            $lastSampleTs = $queryMaxTs;
        } elseif ($queryMaxTs !== null && $queryMaxTs > $lastSampleTs) {
            $lastSampleTs = $queryMaxTs;
        }

        return $lastSampleTs !== null
            ? Carbon::createFromTimestampUTC($lastSampleTs)->toIso8601ZuluString()
            : null;
    }
}
