<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use App\Support\Cast;
use Illuminate\Support\Facades\DB;

/**
 * Manages MySQL RANGE partitions and bounded retention cleanup for VM metrics tables.
 */
final class MetricsPartitionManager
{
    /**
     * Partition step, name prefix, and lookahead window (seconds) per partitioned metrics table.
     *
     * @var array<string, array{step: int, prefix: string, lookahead: int}>
     */
    private const array PARTITION_SPECS = [
        'vm_metric_samples' => ['step' => 21600, 'prefix' => 'p_raw_', 'lookahead' => 172800],
        'vm_metric_rollups_5m' => ['step' => 86400, 'prefix' => 'p_5m_', 'lookahead' => 259200],
        'vm_metric_rollups_1h' => ['step' => 604800, 'prefix' => 'p_1h_', 'lookahead' => 1209600],
    ];

    public function __construct(
        private readonly ?VmMetricsConfigService $config = null,
    ) {}

    private function config(): VmMetricsConfigService
    {
        return $this->config ?? app(VmMetricsConfigService::class);
    }

    /**
     * Ensure future RANGE partitions exist ahead of current time for all metrics tables on MySQL.
     */
    public function ensureFuturePartitions(?int $nowEpoch = null): void
    {
        $conn = DB::connection();
        if ($conn->getDriverName() !== 'mysql') {
            return;
        }

        $now = $nowEpoch ?? time();
        $schema = $conn->getDatabaseName();

        foreach (self::PARTITION_SPECS as $table => $spec) {
            $rows = $conn->select(
                'SELECT PARTITION_NAME, PARTITION_DESCRIPTION FROM information_schema.PARTITIONS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND PARTITION_NAME IS NOT NULL',
                [$schema, $table],
            );

            if ($rows === []) {
                continue;
            }

            $existingNames = [];
            $hasFuture = false;
            $maxBound = 0;

            foreach ($rows as $row) {
                $attrs = $this->extractRowAttributes($row);
                $name = Cast::string($attrs['PARTITION_NAME'] ?? $attrs['partition_name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $existingNames[$name] = true;
                if ($name === 'p_future') {
                    $hasFuture = true;
                }

                $desc = Cast::string($attrs['PARTITION_DESCRIPTION'] ?? $attrs['partition_description'] ?? '');
                if ($desc !== '' && ctype_digit($desc)) {
                    $bound = (int) $desc;
                    if ($bound > $maxBound) {
                        $maxBound = $bound;
                    }
                }
            }

            if (! $hasFuture) {
                continue;
            }

            $step = $spec['step'];
            $prefix = $spec['prefix'];
            $targetHorizon = $now + $spec['lookahead'];
            $start = max($maxBound, intdiv($now, $step) * $step) + $step;

            $clauses = [];
            for ($bound = $start; $bound <= $targetHorizon; $bound += $step) {
                $baseName = $prefix.gmdate('Ymd_His', $bound);
                $partName = $baseName;
                $suffix = 1;

                while (isset($existingNames[$partName])) {
                    $partName = $baseName.'_'.$suffix;
                    $suffix++;
                }

                $existingNames[$partName] = true;
                $clauses[] = sprintf('PARTITION `%s` VALUES LESS THAN (%d)', $partName, $bound);
            }

            if ($clauses !== []) {
                $conn->statement(sprintf(
                    'ALTER TABLE `%s` REORGANIZE PARTITION p_future INTO (%s, PARTITION p_future VALUES LESS THAN MAXVALUE)',
                    $table,
                    implode(', ', $clauses),
                ));
            }
        }
    }

    /**
     * Drop expired partitions (MySQL) and run bounded batch deletes for expired metric rows.
     *
     * Runs unconditionally even when metrics collection is disabled.
     *
     * @return array{dropped_partitions: int, deleted_raw: int, deleted_5m: int, deleted_1h: int}
     */
    public function pruneExpiredData(?int $nowEpoch = null): array
    {
        $now = $nowEpoch ?? time();
        $cfg = $this->config();

        $cutoffs = [
            'vm_metric_samples' => $now - ($cfg->rawRetentionHours() * 3600),
            'vm_metric_rollups_5m' => $now - ($cfg->rollup5mRetentionDays() * 86400),
            'vm_metric_rollups_1h' => $now - ($cfg->rollup1hRetentionDays() * 86400),
        ];

        $droppedPartitions = 0;
        $conn = DB::connection();

        if ($conn->getDriverName() === 'mysql') {
            $schema = $conn->getDatabaseName();

            foreach ($cutoffs as $table => $cutoff) {
                $rows = $conn->select(
                    'SELECT PARTITION_NAME, PARTITION_DESCRIPTION FROM information_schema.PARTITIONS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND PARTITION_NAME IS NOT NULL',
                    [$schema, $table],
                );

                $nonFuture = [];
                foreach ($rows as $row) {
                    $attrs = $this->extractRowAttributes($row);
                    $name = Cast::string($attrs['PARTITION_NAME'] ?? $attrs['partition_name'] ?? '');
                    $desc = Cast::string($attrs['PARTITION_DESCRIPTION'] ?? $attrs['partition_description'] ?? '');

                    if ($name === '' || $name === 'p_future' || $desc === '' || ! ctype_digit($desc)) {
                        continue;
                    }

                    $nonFuture[] = [
                        'name' => $name,
                        'bound' => (int) $desc,
                    ];
                }

                usort(
                    $nonFuture,
                    static fn (array $a, array $b): int => $a['bound'] <=> $b['bound'],
                );

                $remaining = count($nonFuture);
                foreach ($nonFuture as $partition) {
                    if ($remaining <= 1) {
                        break;
                    }

                    if ($partition['bound'] <= $cutoff && preg_match('/^[a-zA-Z0-9_]+$/', $partition['name']) === 1) {
                        $conn->statement(sprintf('ALTER TABLE `%s` DROP PARTITION `%s`', $table, $partition['name']));
                        $droppedPartitions++;
                        $remaining--;
                    }
                }
            }
        }

        return [
            'dropped_partitions' => $droppedPartitions,
            'deleted_raw' => $this->batchDeleteExpired('vm_metric_samples', $cutoffs['vm_metric_samples']),
            'deleted_5m' => $this->batchDeleteExpired('vm_metric_rollups_5m', $cutoffs['vm_metric_rollups_5m']),
            'deleted_1h' => $this->batchDeleteExpired('vm_metric_rollups_1h', $cutoffs['vm_metric_rollups_1h']),
        ];
    }

    /**
     * Delete expired rows from a metrics table in bounded batches.
     */
    private function batchDeleteExpired(string $table, int $cutoff, int $batchSize = 5000, int $maxBatches = 20): int
    {
        $conn = DB::connection();
        $totalDeleted = 0;

        if ($conn->getDriverName() === 'mysql') {
            for ($batch = 0; $batch < $maxBatches; $batch++) {
                $deleted = $conn->affectingStatement(
                    sprintf('DELETE FROM `%s` WHERE bucket_ts < ? LIMIT %d', $table, $batchSize),
                    [$cutoff],
                );
                $totalDeleted += $deleted;

                if ($deleted < $batchSize) {
                    break;
                }
            }

            return $totalDeleted;
        }

        return $conn->table($table)
            ->where('bucket_ts', '<', $cutoff)
            ->delete();
    }

    /**
     * Extract an associative array from a raw DB::select() row.
     *
     * @return array<string, mixed>
     */
    private function extractRowAttributes(mixed $row): array
    {
        $raw = is_object($row) ? get_object_vars($row) : (is_array($row) ? $row : []);

        $result = [];
        foreach ($raw as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
