<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\CollectHostMetricsJob;
use App\Models\Host;
use App\Services\Metrics\VmMetricsConfigService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Artisan command to dispatch per-node instance metrics collection jobs (FR-2, FR-11).
 */
final class MetricsCollectCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'metrics:collect {host? : Optional host ID or name} {--sync : Run synchronously in-process instead of dispatching queue jobs} {--now= : Optional UTC epoch override for testing}';

    /**
     * @var string
     */
    protected $description = 'Poll active compute nodes for instance metrics via HypervisorGateway';

    public function handle(VmMetricsConfigService $config): int
    {
        if (! $config->isEnabled()) {
            $this->info('Instance metrics collection is disabled (metrics.enabled = false).');

            return self::SUCCESS;
        }

        $nowOpt = $this->option('now');
        $nowEpoch = is_numeric($nowOpt) ? (int) $nowOpt : null;
        $nowCarbon = $nowEpoch !== null ? Carbon::createFromTimestampUTC($nowEpoch) : Carbon::now();

        $hostArg = $this->argument('host');
        $query = Host::query()
            ->where('is_active', true)
            ->whereIn('driver', ['incus', 'lxd', 'fake']);

        if (is_string($hostArg) && $hostArg !== '') {
            $query->where(function (Builder $q) use ($hostArg): void {
                $q->where('id', $hostArg)->orWhere('name', $hostArg);
            });
        }

        $hosts = $query->get();
        $dispatched = 0;
        $runSync = (bool) $this->option('sync') || app()->runningUnitTests() || config('queue.default') === 'sync';

        foreach ($hosts as $host) {
            if (in_array($host->status, ['fingerprint_mismatch', 'not_trusted'], true)) {
                continue;
            }

            if ($host->metrics_backoff_until !== null && $host->metrics_backoff_until->greaterThan($nowCarbon)) {
                continue;
            }

            if ($runSync) {
                CollectHostMetricsJob::dispatchSync($host->id, $nowEpoch);
            } else {
                CollectHostMetricsJob::dispatch($host->id, $nowEpoch);
            }
            $dispatched++;
        }

        $this->info(sprintf('Metrics collection triggered for %d active host(s).', $dispatched));

        return self::SUCCESS;
    }
}
