<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Metrics\MetricsPartitionManager;
use Illuminate\Console\Command;

/**
 * Artisan command to maintain MySQL partitions and prune expired metric samples and rollups (FR-7, FR-11).
 */
final class MetricsPruneCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'metrics:prune {--now= : Optional UTC epoch override for testing}';

    /**
     * @var string
     */
    protected $description = 'Maintain metrics partitions and prune expired raw samples and rollups';

    public function handle(MetricsPartitionManager $partitionManager): int
    {
        $nowOpt = $this->option('now');
        $nowEpoch = is_numeric($nowOpt) ? (int) $nowOpt : null;

        $partitionManager->ensureFuturePartitions($nowEpoch);
        $stats = $partitionManager->pruneExpiredData($nowEpoch);

        $this->info(sprintf(
            'Metrics prune finished. Partitions dropped: %d, Deleted rows (raw: %d, 5m: %d, 1h: %d).',
            $stats['dropped_partitions'],
            $stats['deleted_raw'],
            $stats['deleted_5m'],
            $stats['deleted_1h']
        ));

        return self::SUCCESS;
    }
}
