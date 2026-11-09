<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Host;
use App\Services\VmReconciliationService;
use Illuminate\Console\Command;

/**
 * Artisan command to reconcile virtual machines and record state drift.
 */
class VmReconcileCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vm:reconcile {host? : Host ID or name to reconcile}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reconcile virtual machines between database and Incus compute nodes to detect drift';

    public function __construct(
        protected readonly VmReconciliationService $reconciliationService,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $hostArg = $this->argument('host');
        $targetHost = null;

        if ($hostArg !== null && $hostArg !== '') {
            $targetHost = Host::query()
                ->where('id', $hostArg)
                ->orWhere('name', $hostArg)
                ->first();

            if ($targetHost === null) {
                $this->error(sprintf('Host [%s] not found.', $hostArg));

                return self::FAILURE;
            }
        }

        $this->info('Starting VM state reconciliation...');
        $stats = $this->reconciliationService->reconcile($targetHost);

        $this->info(sprintf(
            'Reconciliation finished. Hosts checked: %d, Drifts detected: %d, Drifts resolved: %d.',
            $stats['hosts_checked'],
            $stats['drifts_detected'],
            $stats['drifts_resolved']
        ));

        return self::SUCCESS;
    }
}
