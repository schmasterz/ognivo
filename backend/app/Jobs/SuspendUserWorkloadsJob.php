<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\QueueName;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\VmLifecycleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asynchronously suspends all active tenant workloads one by one through VmLifecycleService.
 */
final class SuspendUserWorkloadsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(
        public readonly User $user,
    ) {
        $this->onQueue(QueueName::Heavy);
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("user-suspend:{$this->user->id}"))
                ->releaseAfter(30)
                ->expireAfter(300),
        ];
    }

    /**
     * Execute the job: suspend VMs one by one and record failures.
     */
    public function handle(VmLifecycleService $lifecycleService): void
    {
        Log::info(sprintf('Starting workload suspension for user #%d (%s)', $this->user->id, $this->user->email));

        /** @var Collection<int, VirtualMachine> $vms */
        $vms = $this->user->virtualMachines()
            ->with('host')
            ->whereNotIn('status', ['deleted', 'deleting', 'failed', 'suspended'])
            ->get();

        $failures = [];

        foreach ($vms as $vm) {
            try {
                $lifecycleService->suspend($vm);
                Log::info(sprintf('Successfully suspended VM #%d (%s) for user #%d', $vm->id, $vm->name, $this->user->id));
            } catch (Throwable $e) {
                $failures[$vm->id] = $e->getMessage();
                Log::error(sprintf('Failed to suspend VM #%d (%s) for user #%d: %s', $vm->id, $vm->name, $this->user->id, $e->getMessage()), [
                    'exception' => $e,
                ]);
            }
        }

        if (! empty($failures)) {
            Log::warning(sprintf('Workload suspension for user #%d completed with %d failure(s)', $this->user->id, count($failures)), [
                'failures' => $failures,
            ]);
        } else {
            Log::info(sprintf('Successfully suspended all active workloads for user #%d', $this->user->id));
        }
    }
}
