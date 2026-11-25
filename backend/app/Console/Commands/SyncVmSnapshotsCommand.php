<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Host;
use App\Models\VirtualMachine;
use App\Services\VmSnapshotService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Artisan command to synchronize snapshots for virtual machines from hypervisor backup targets.
 */
class SyncVmSnapshotsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'snapshots:sync {host? : Host ID or name to sync snapshots for}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Synchronize virtual machine snapshots from hypervisor backup targets';

    public function __construct(
        protected readonly VmSnapshotService $snapshotService,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $hostArg = $this->argument('host');
        $query = VirtualMachine::query()
            ->whereNotIn('status', ['deleted']);

        if ($hostArg !== null && $hostArg !== '') {
            $targetHost = Host::query()
                ->where('id', $hostArg)
                ->orWhere('name', $hostArg)
                ->first();

            if ($targetHost === null) {
                $this->error(sprintf('Host [%s] not found.', $hostArg));

                return self::FAILURE;
            }

            $query->where('host_id', $targetHost->id);
        } else {
            $activeHostIds = Host::query()->where('is_active', true)->pluck('id');
            $query->whereIn('host_id', $activeHostIds);
        }

        $vms = $query->get();
        $synced = 0;
        $failed = 0;

        foreach ($vms as $vm) {
            try {
                $this->snapshotService->syncFromHypervisor($vm);
                $synced++;
            } catch (Throwable $e) {
                $failed++;
                Log::warning(sprintf('Failed to sync snapshots for VM #%d (%s): %s', $vm->id, $vm->name, $e->getMessage()));
            }
        }

        $this->info(sprintf('Snapshot synchronization completed. Synced: %d VMs, Failed: %d.', $synced, $failed));

        return self::SUCCESS;
    }
}
