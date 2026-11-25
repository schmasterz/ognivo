<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Enums\QueueName;
use App\Models\VirtualMachine;
use App\Services\VmLifecycleService;
use App\States\Vm\Deleted;
use App\States\Vm\Failed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Asynchronous job to execute virtual machine lifecycle actions via VmLifecycleService.
 */
final class ExecuteVmActionJob implements ShouldQueue
{
    use Queueable;

    /**
     * Number of retry attempts.
     */
    public int $tries = 3;

    /**
     * Timeout for lifecycle operations (seconds).
     */
    public int $timeout = 180;

    public function __construct(
        public readonly VirtualMachine $vm,
        public readonly string $action,
        public readonly bool $force = false,
    ) {
        $this->onQueue(QueueName::High);
    }

    /**
     * Get the middleware the job should pass through.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("vm:{$this->vm->id}"))
                ->releaseAfter(30)
                ->expireAfter(240),
        ];
    }

    /**
     * Execute the lifecycle action on the hypervisor driver via VmLifecycleService.
     */
    public function handle(VmLifecycleService $lifecycleService): void
    {
        Log::info(sprintf('Executing VM action "%s" on VM #%d (%s)', $this->action, $this->vm->id, $this->vm->name));

        try {
            match ($this->action) {
                'start' => $lifecycleService->start($this->vm),
                'stop' => $lifecycleService->stop($this->vm, $this->force),
                'restart' => $lifecycleService->restart($this->vm),
                'suspend' => $lifecycleService->suspend($this->vm),
                'delete' => $lifecycleService->delete($this->vm, $this->force),
                default => throw new InvalidArgumentException(sprintf('Unsupported VM action "%s"', $this->action)),
            };

            Log::info(sprintf('Successfully completed VM action "%s" on VM #%d', $this->action, $this->vm->id));
        } catch (Throwable $e) {
            Log::error(sprintf('Exception during VM action "%s" on VM #%d: %s', $this->action, $this->vm->id, $e->getMessage()), [
                'exception' => $e,
            ]);

            if ($this->action === 'delete') {
                if (! $this->vm->status->equals(Failed::class) && ! $this->vm->status->equals(Deleted::class)) {
                    $this->vm->status->transitionTo(Failed::class);
                    $this->vm->save();
                }
            }

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error(sprintf('ExecuteVmActionJob failed for action "%s" on VM #%d (%s): %s', $this->action, $this->vm->id, $this->vm->name, $exception?->getMessage()), [
            'exception' => $exception,
        ]);

        if ($this->action === 'delete') {
            if (! $this->vm->status->equals(Failed::class) && ! $this->vm->status->equals(Deleted::class)) {
                $this->vm->status->transitionTo(Failed::class);
                $this->vm->save();
            }
        }
    }
}
