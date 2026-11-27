<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Asynchronous job to remove all stored metrics for a deleted instance in bounded batches (FR-12).
 */
final class PurgeVmMetricsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly int $virtualMachineId,
    ) {
        $this->onQueue(QueueName::Default);
    }

    /**
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("purge-vm-metrics-{$this->virtualMachineId}"))
                ->expireAfter(120),
        ];
    }

    public function handle(): void
    {
        Cache::forget("metrics:counters:{$this->virtualMachineId}");
        Cache::forget("metrics:totals_latest:{$this->virtualMachineId}");

        $driver = DB::connection()->getDriverName();
        $tables = [
            'vm_metric_samples',
            'vm_metric_rollups_5m',
            'vm_metric_rollups_1h',
            'vm_metric_totals',
        ];

        foreach ($tables as $table) {
            if ($driver === 'mysql') {
                do {
                    $deleted = DB::affectingStatement(
                        "DELETE FROM `{$table}` WHERE virtual_machine_id = ? LIMIT 2000",
                        [$this->virtualMachineId]
                    );
                } while ($deleted > 0);
            } else {
                DB::table($table)->where('virtual_machine_id', $this->virtualMachineId)->delete();
            }
        }
    }
}
