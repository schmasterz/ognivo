<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Enums\QueueName;
use App\Models\Host;
use App\Services\Metrics\VmMetricsCollectorService;
use App\Services\Metrics\VmMetricsConfigService;
use App\States\Host\FingerprintMismatch;
use App\States\Host\NotTrusted;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Isolated per-node metrics collection job (NFR-2).
 */
final class CollectHostMetricsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 15;

    public function __construct(
        public readonly int $hostId,
        public readonly ?int $nowEpoch = null,
    ) {
        $this->onQueue(QueueName::Metrics);
    }

    /**
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("metrics-collect-host-{$this->hostId}"))
                ->dontRelease()
                ->expireAfter(30),
        ];
    }

    public function handle(VmMetricsCollectorService $collector, VmMetricsConfigService $config): void
    {
        if (! $config->isEnabled()) {
            return;
        }

        $lock = Cache::lock("metrics:lock:host:{$this->hostId}", 25);
        if (! $lock->get()) {
            return;
        }

        try {
            $host = Host::find($this->hostId);
            if (
                $host === null
                || ! $host->is_active
                || $host->status->equals(FingerprintMismatch::class, NotTrusted::class)
            ) {
                return;
            }

            $t0 = microtime(true);
            try {
                $collector->collectForHost($host, $this->nowEpoch);
            } catch (Throwable $e) {
                $durationMs = max(1, (int) round((microtime(true) - $t0) * 1000.0));
                $collector->recordHostFailure($host, $e, $durationMs, $this->nowEpoch);
            }
        } finally {
            $lock->release();
        }
    }
}
