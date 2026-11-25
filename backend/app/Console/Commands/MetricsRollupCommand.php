<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Metrics\VmMetricsRollupService;
use Illuminate\Console\Command;

/**
 * Artisan command to aggregate closed 5-minute and 1-hour metric rollup buckets (FR-6, FR-11).
 */
final class MetricsRollupCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'metrics:rollup {--now= : Optional UTC epoch override for testing}';

    /**
     * @var string
     */
    protected $description = 'Aggregate closed 5-minute and 1-hour instance metric rollups';

    public function handle(VmMetricsRollupService $rollupService): int
    {
        $nowOpt = $this->option('now');
        $nowEpoch = is_numeric($nowOpt) ? (int) $nowOpt : null;

        $stats = $rollupService->rollup($nowEpoch);

        $this->info(sprintf(
            'Metrics rollup finished. 5m buckets: %d, 1h buckets: %d.',
            $stats['rolled_5m'],
            $stats['rolled_1h']
        ));

        return self::SUCCESS;
    }
}
