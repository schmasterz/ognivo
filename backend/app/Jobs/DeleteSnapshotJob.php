<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\BackupTargetInterface;
use App\Events\SnapshotStatusUpdated;
use App\Jobs\Enums\QueueName;
use App\Models\VirtualMachine;
use App\Models\VmSnapshot;
use App\States\Vm\Deleted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Asynchronous job to delete a virtual machine snapshot from the configured BackupTargetInterface.
 */
final class DeleteSnapshotJob implements ShouldQueue
{
    use Queueable;

    /**
     * Number of retry attempts.
     */
    public int $tries = 3;

    /**
     * Timeout for snapshot deletion operations (seconds).
     */
    public int $timeout = 180;

    public function __construct(
        public readonly VmSnapshot $snapshot,
    ) {
        $this->onQueue(QueueName::Heavy);
    }

    /**
     * Get the middleware the job should pass through.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("vm:{$this->snapshot->virtual_machine_id}"))
                ->shared()
                ->releaseAfter(30)
                ->expireAfter(240),
        ];
    }

    /**
     * Execute the snapshot deletion on the backup target.
     */
    public function handle(?BackupTargetInterface $backupTarget = null): void
    {
        $vmId = $this->snapshot->virtual_machine_id;
        $lock = Cache::lock("vm:{$vmId}", 30);

        if (! $lock->get()) {
            $this->release(10);

            return;
        }

        try {
            // Idempotency: if the snapshot row has already been deleted, do nothing
            if (! VmSnapshot::whereKey($this->snapshot->id)->exists()) {
                return;
            }

            $this->snapshot->refresh();

            $target = $backupTarget ?? app(BackupTargetInterface::class);
            /** @var VirtualMachine|null $vm */
            $vm = $this->snapshot->virtualMachine()->first();

            if ($vm !== null && ! $vm->status->equals(Deleted::class)) {
                Log::info(sprintf('Deleting snapshot "%s" on VM #%d (%s)', $this->snapshot->name, $vm->id, $vm->name));

                $isFailed = $this->snapshot->status === VmSnapshot::STATUS_FAILED;
                try {
                    $deleted = $target->delete($vm, $this->snapshot->name);
                    if (! $deleted && ! $isFailed) {
                        throw new RuntimeException(sprintf(
                            'Backup target failed to delete snapshot "%s" on VM #%d (%s).',
                            $this->snapshot->name,
                            $vm->id,
                            $vm->name
                        ));
                    }
                } catch (Throwable $e) {
                    if (! $isFailed) {
                        throw $e;
                    }
                    Log::warning(sprintf('Ignoring backup target error while deleting failed snapshot "%s" on VM #%d: %s', $this->snapshot->name, $vm->id, $e->getMessage()));
                }
            }

            $deletedSnapshot = clone $this->snapshot;
            $this->snapshot->delete();

            event(new SnapshotStatusUpdated($deletedSnapshot, 'deleted'));

            Log::info(sprintf('Snapshot "%s" deleted successfully on VM #%d', $deletedSnapshot->name, $vmId));
        } catch (Throwable $e) {
            Log::error(sprintf('Failed to delete snapshot "%s" on VM #%d: %s', $this->snapshot->name, $vmId, $e->getMessage()), [
                'exception' => $e,
            ]);

            $this->markFailed($e->getMessage());

            throw $e;
        } finally {
            $lock->release();
        }
    }

    /**
     * Handle a permanent job failure.
     */
    public function failed(?Throwable $exception): void
    {
        $reason = $exception?->getMessage() ?? 'Snapshot deletion failed.';

        Log::error(sprintf('DeleteSnapshotJob failed permanently for snapshot #%d (%s): %s', $this->snapshot->id, $this->snapshot->name, $reason), [
            'exception' => $exception,
        ]);

        $this->markFailed($reason);
    }

    /**
     * Persist failure status and error message on the snapshot record.
     */
    private function markFailed(string $reason): void
    {
        if (! VmSnapshot::whereKey($this->snapshot->id)->exists()) {
            return;
        }

        $this->snapshot->refresh();
        $this->snapshot->status = VmSnapshot::STATUS_FAILED;
        $this->snapshot->error = $reason;
        $this->snapshot->save();

        try {
            event(new SnapshotStatusUpdated($this->snapshot, VmSnapshot::STATUS_FAILED));
        } catch (Throwable) {
            // Ignore broadcast error during failure handling
        }
    }
}
