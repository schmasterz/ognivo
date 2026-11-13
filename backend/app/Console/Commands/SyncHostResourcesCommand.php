<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Host;
use App\Services\HostNodeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SyncHostResourcesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hosts:sync-resources {host? : The ID or name of a specific host to sync}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Synchronize compute and storage capacity from hypervisors for discovered and unknown hosts (never overwrites manual)';

    /**
     * Execute the console command.
     */
    public function handle(HostNodeService $hostNodeService): int
    {
        $hostArg = $this->argument('host');

        if ($hostArg !== null && $hostArg !== '') {
            $host = is_numeric($hostArg)
                ? Host::find((int) $hostArg)
                : Host::where('name', (string) $hostArg)->first();

            if ($host === null) {
                $this->error(sprintf('Host [%s] not found.', $hostArg));

                return self::FAILURE;
            }

            if ($host->capacity_source === 'manual') {
                $this->warn(sprintf('Host [%s] has manually configured capacity; skipping sync.', $host->name));

                return self::SUCCESS;
            }

            try {
                $hostNodeService->syncHostResources($host);
                $this->info(sprintf(
                    'Host [%s] resources synced: %d cores, %d MB RAM, %d GB storage.',
                    $host->name,
                    $host->total_cpu_cores,
                    $host->total_ram_mb,
                    $host->total_storage_gb
                ));

                return self::SUCCESS;
            } catch (Throwable $e) {
                $this->error(sprintf('Failed to sync resources for host [%s]: %s', $host->name, $e->getMessage()));
                Log::error(sprintf('Failed to sync resources for host [%s]: %s', $host->name, $e->getMessage()));

                return self::FAILURE;
            }
        }

        $hosts = Host::whereIn('capacity_source', ['discovered', 'unknown'])
            ->where('is_active', true)
            ->get();

        if ($hosts->isEmpty()) {
            $this->info('No discovered or unknown hosts found to synchronize.');

            return self::SUCCESS;
        }

        $successCount = 0;
        $failCount = 0;

        foreach ($hosts as $host) {
            try {
                $hostNodeService->syncHostResources($host);
                $this->line(sprintf('Synced host [%s] (ID: %d)', $host->name, $host->id));
                $successCount++;
            } catch (Throwable $e) {
                $this->warn(sprintf('Failed syncing host [%s] (ID: %d): %s', $host->name, $host->id, $e->getMessage()));
                Log::warning(sprintf('Periodic sync failed for host [%s]: %s', $host->name, $e->getMessage()));
                $failCount++;
            }
        }

        $this->info(sprintf('Host resource sync completed. %d synced, %d failed.', $successCount, $failCount));

        return self::SUCCESS;
    }
}
