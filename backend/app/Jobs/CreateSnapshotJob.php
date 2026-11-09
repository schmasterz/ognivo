<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\BackupTargetInterface;
use App\Events\SnapshotCreated;
use App\Events\SnapshotStatusUpdated;
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
 * Asynchronous job to create a virtual machine snapshot on the configured BackupTargetInterface.
 */
final class CreateSnapshotJob implements ShouldQueue
{
    use Queueable;

    /**
     * Number of retry attempts.
     */
    public int $tries = 3;

    /**
     * Timeout for snapshot creation operations (seconds).
     */
    public int $timeout = 180;

    public function __construct(
        public readonly VmSnapshot $snapshot,
    ) {}

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
     * Execute the snapshot creation on the backup target.
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
            if (! VmSnapshot::whereKey($this->snapshot->id)->exists()) {
                return;
            }

            $this->snapshot->refresh();

            // Idempotency: if already ready or deleting, do not re-create on the target
            if (in_array($this->snapshot->status, [VmSnapshot::STATUS_READY, VmSnapshot::STATUS_DELETING], true)) {
                return;
            }

            $target = $backupTarget ?? app(BackupTargetInterface::class);
            /** @var VirtualMachine|null $vm */
            $vm = $this->snapshot->virtualMachine()->first();

            if ($vm === null || $vm->status->equals(Deleted::class)) {
                throw new RuntimeException(sprintf('Virtual machine #%d does not exist or has been deleted.', $vmId));
            }

            Log::info(sprintf('Creating snapshot "%s" on VM #%d (%s)', $this->snapshot->name, $vm->id, $vm->name));

            $created = $target->create($vm, $this->snapshot->name, $this->snapshot->stateful);
            if (! $created) {
                throw new RuntimeException(sprintf(
                    'Backup target failed to create snapshot "%s" on VM #%d (%s).',
                    $this->snapshot->name,
                    $vm->id,
                    $vm->name
                ));
            }

            $this->snapshot->status = VmSnapshot::STATUS_READY;
            $this->snapshot->error = null;
            $this->snapshot->save();

            event(new SnapshotStatusUpdated($this->snapshot, VmSnapshot::STATUS_READY));
            event(new SnapshotCreated($this->snapshot, $vm));

            Log::info(sprintf('Snapshot "%s" created successfully on VM #%d (%s)', $this->snapshot->name, $vm->id, $vm->name));
        } catch (Throwable $e) {
            Log::error(sprintf('Failed to create snapshot "%s" on VM #%d: %s', $this->snapshot->name, $vmId, $e->getMessage()), [
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
        $reason = $exception?->getMessage() ?? 'Snapshot creation failed.';

        Log::error(sprintf('CreateSnapshotJob failed permanently for snapshot #%d (%s): %s', $this->snapshot->id, $this->snapshot->name, $reason), [
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
