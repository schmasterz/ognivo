<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\VirtualMachine;
use App\Services\ProvisioningCompensator;
use App\States\Vm\Provisioning;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SweepStaleProvisioningCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vm:sweep-stale-provisioning {--max-age-seconds= : Override cutoff age in seconds}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fail and compensate VMs stuck in provisioning past the job deadline';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $maxAgeOption = $this->option('max-age-seconds');
        $configuredDeadline = config('provisioning.job_deadline_seconds');

        $deadlineSeconds = is_numeric($maxAgeOption)
            ? (int) $maxAgeOption
            : ((is_numeric($configuredDeadline) ? (int) $configuredDeadline : 900) + 120);

        $cutoff = now()->subSeconds($deadlineSeconds);

        /** @var Collection<int, VirtualMachine> $staleVms */
        $staleVms = VirtualMachine::query()
            ->where('status', 'provisioning')
            ->where('updated_at', '<=', $cutoff)
            ->get();

        $sweptCount = 0;

        foreach ($staleVms as $vm) {
            $lock = Cache::lock("vm:{$vm->id}", 30);

            if (! $lock->get()) {
                $this->line(sprintf('Skipping VM #%d (%s): lock is held by another process.', $vm->id, $vm->name));

                continue;
            }

            try {
                $vm->refresh();

                if (! $vm->status->equals(Provisioning::class)) {
                    continue;
                }

                if ($vm->updated_at === null || ! $vm->updated_at->lessThanOrEqualTo($cutoff)) {
                    continue;
                }

                $this->warn(sprintf('Failing and compensating stale provisioning VM #%d (%s)...', $vm->id, $vm->name));

                ProvisioningCompensator::compensate($vm, 'Provisioning timed out (stale provisioning sweeper).');

                $sweptCount++;
            } catch (Throwable $e) {
                Log::error(sprintf('Failed to sweep stale provisioning VM #%d (%s): %s', $vm->id, $vm->name, $e->getMessage()), [
                    'exception' => $e,
                ]);
                $this->error(sprintf('Error sweeping VM #%d: %s', $vm->id, $e->getMessage()));
            } finally {
                $lock->release();
            }
        }

        $this->info(sprintf('Swept %d stale provisioning VM(s).', $sweptCount));

        return self::SUCCESS;
    }
}
