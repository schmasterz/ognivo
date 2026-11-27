<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use App\DTO\Cast;
use App\DTO\Incus\RawInstanceMetricsDTO;
use App\Exceptions\FingerprintMismatchException;
use App\Exceptions\MissingPinException;
use App\Models\Host;
use App\Models\VirtualMachine;
use App\Services\Hypervisor\HypervisorManager;
use App\States\Host\FingerprintMismatch;
use App\States\Host\NotTrusted;
use App\States\Host\Ok;
use App\States\Host\Unreachable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Collects per-host instance metrics from hypervisor /1.0/metrics endpoints,
 * converts cumulative counters into per-interval rates, detects counter resets
 * and gap windows, and persists bulk samples and capacity totals.
 */
final class VmMetricsCollectorService
{
    public const int BIT_CPU = 1; // 1 << 0

    public const int BIT_RAM = 2; // 1 << 1

    public const int BIT_DISK_SPACE = 4; // 1 << 2

    public const int BIT_DISK_IO = 8; // 1 << 3

    public const int BIT_NETWORK = 16; // 1 << 4

    public const int MASK_ALL = 31;

    public function __construct(
        private readonly ?HypervisorManager $hypervisorManager = null,
        private readonly ?VmMetricsConfigService $config = null,
    ) {}

    /**
     * Compute a single metric sample and updated Redis counter state from raw DTO counters.
     *
     * @param  array<string, mixed>|null  $priorCounters
     * @return array{
     *     sample: array{
     *         virtual_machine_id: int,
     *         bucket_ts: int,
     *         avail_mask: int,
     *         cpu_usage_bp: int|null,
     *         ram_used_bytes: int|null,
     *         disk_used_bytes: int|null,
     *         disk_read_bps: int|null,
     *         disk_write_bps: int|null,
     *         net_rx_bps: int|null,
     *         net_tx_bps: int|null
     *     },
     *     next_counters: array<string, mixed>
     * }
     */
    public function computeSampleFromDto(
        RawInstanceMetricsDTO $dto,
        int $cpuCores,
        int $nowEpoch,
        int $bucketTs,
        int $intervalSeconds,
        ?array $priorCounters,
        int $virtualMachineId,
    ): array {
        if ($priorCounters !== null && isset($priorCounters['bucket_ts']) && Cast::int($priorCounters['bucket_ts']) === $bucketTs) {
            // Unwrap prior baseline on duplicate concurrent runs so the bucket remains idempotent.
            $baseline = isset($priorCounters['prev']) ? Cast::arrayOrNull($priorCounters['prev']) : null;
        } else {
            $baseline = $priorCounters;
        }

        $availMask = 0;
        $ramUsed = null;
        $diskUsed = null;

        if ($dto->cpuBusySecondsTotal !== null) {
            $availMask |= self::BIT_CPU;
        }
        if ($dto->memoryTotalBytes !== null && $dto->memoryAvailableBytes !== null) {
            $availMask |= self::BIT_RAM;
            $ramUsed = max(0, $dto->memoryTotalBytes - $dto->memoryAvailableBytes);
        }
        if ($dto->filesystemTotalBytes !== null && $dto->filesystemAvailBytes !== null && $dto->filesystemTotalBytes > 0) {
            $availMask |= self::BIT_DISK_SPACE;
            $diskUsed = max(0, $dto->filesystemTotalBytes - $dto->filesystemAvailBytes);
        }
        if ($dto->diskReadBytesTotal !== null && $dto->diskWriteBytesTotal !== null) {
            $availMask |= self::BIT_DISK_IO;
        }
        if ($dto->netRxBytesTotal !== null && $dto->netTxBytesTotal !== null) {
            $availMask |= self::BIT_NETWORK;
        }

        $priorTs = ($baseline !== null && isset($baseline['ts']) && is_numeric($baseline['ts']))
            ? (int) $baseline['ts']
            : null;
        $deltaT = $priorTs !== null ? ($nowEpoch - $priorTs) : null;
        $maxGapSeconds = (int) floor(2.5 * (float) max(30, $intervalSeconds));
        $validWindow = $deltaT !== null && $deltaT > 0 && $deltaT <= $maxGapSeconds;

        $cpuUsageBp = null;
        if (
            $validWindow
            && $dto->cpuBusySecondsTotal !== null
            && isset($baseline['cpu_busy_sec'])
            && is_numeric($baseline['cpu_busy_sec'])
        ) {
            $deltaCpu = $dto->cpuBusySecondsTotal - (float) $baseline['cpu_busy_sec'];
            if ($deltaCpu >= 0.0) {
                $denom = (float) $deltaT * (float) max(1, $cpuCores);
                $pct = ($deltaCpu / $denom) * 100.0;
                if ($pct <= 105.0) {
                    $cpuUsageBp = (int) round(min(100.0, $pct) * 100.0);
                }
            }
        }

        $diskReadBps = $this->computeByteRate(
            $dto->diskReadBytesTotal,
            $baseline['disk_read_bytes'] ?? null,
            $deltaT,
            $validWindow,
        );
        $diskWriteBps = $this->computeByteRate(
            $dto->diskWriteBytesTotal,
            $baseline['disk_write_bytes'] ?? null,
            $deltaT,
            $validWindow,
        );
        $netRxBps = $this->computeByteRate(
            $dto->netRxBytesTotal,
            $baseline['net_rx_bytes'] ?? null,
            $deltaT,
            $validWindow,
        );
        $netTxBps = $this->computeByteRate(
            $dto->netTxBytesTotal,
            $baseline['net_tx_bytes'] ?? null,
            $deltaT,
            $validWindow,
        );

        $nextCounters = [
            'ts' => $nowEpoch,
            'bucket_ts' => $bucketTs,
            'cpu_busy_sec' => $dto->cpuBusySecondsTotal,
            'disk_read_bytes' => $dto->diskReadBytesTotal,
            'disk_write_bytes' => $dto->diskWriteBytesTotal,
            'net_rx_bytes' => $dto->netRxBytesTotal,
            'net_tx_bytes' => $dto->netTxBytesTotal,
            'prev' => $baseline !== null ? [
                'ts' => $baseline['ts'] ?? null,
                'cpu_busy_sec' => $baseline['cpu_busy_sec'] ?? null,
                'disk_read_bytes' => $baseline['disk_read_bytes'] ?? null,
                'disk_write_bytes' => $baseline['disk_write_bytes'] ?? null,
                'net_rx_bytes' => $baseline['net_rx_bytes'] ?? null,
                'net_tx_bytes' => $baseline['net_tx_bytes'] ?? null,
            ] : null,
        ];

        return [
            'sample' => [
                'virtual_machine_id' => $virtualMachineId,
                'bucket_ts' => $bucketTs,
                'avail_mask' => $availMask,
                'cpu_usage_bp' => $cpuUsageBp,
                'ram_used_bytes' => $ramUsed,
                'disk_used_bytes' => $diskUsed,
                'disk_read_bps' => $diskReadBps,
                'disk_write_bps' => $diskWriteBps,
                'net_rx_bps' => $netRxBps,
                'net_tx_bps' => $netTxBps,
            ],
            'next_counters' => $nextCounters,
        ];
    }

    /**
     * Scrape a single host node, map managed VMs, compute samples/totals, bulk upsert, and update host health.
     */
    public function collectForHost(Host $host, ?int $nowEpoch = null): int
    {
        $cfg = $this->config ?? app(VmMetricsConfigService::class);
        if (! $cfg->isEnabled() || ! $host->is_active) {
            return 0;
        }

        $t0 = microtime(true);
        $now = $nowEpoch ?? time();
        $interval = $cfg->intervalSeconds();
        $bucketTs = intdiv($now, $interval) * $interval;

        $manager = $this->hypervisorManager ?? app(HypervisorManager::class);
        $rawMap = $manager->driverForHost($host)->getHostInstanceMetrics($host, $cfg->requestTimeoutSeconds());

        $managedVms = VirtualMachine::query()
            ->where('host_id', $host->id)
            ->where('status', '!=', 'deleted')
            ->get(['id', 'name', 'incus_project', 'instance_type', 'cpu_cores', 'ram_mb', 'disk_gb']);

        /** @var array<string, VirtualMachine> $vmByProjectName */
        $vmByProjectName = [];
        foreach ($managedVms as $vm) {
            $project = $vm->incus_project !== '' ? $vm->incus_project : 'default';
            $vmByProjectName[sprintf('%s/%s', $project, $vm->name)] = $vm;
        }

        /** @var array<string, VirtualMachine> $targetVms */
        $targetVms = [];
        foreach ($rawMap as $key => $dto) {
            if (isset($vmByProjectName[$key])) {
                $targetVms[$key] = $vmByProjectName[$key];
            }
        }

        $counterKeys = [];
        $totalsKeys = [];
        foreach ($targetVms as $vm) {
            $counterKeys[$vm->id] = "metrics:counters:{$vm->id}";
            $totalsKeys[$vm->id] = "metrics:totals_latest:{$vm->id}";
        }

        /** @var array<string, mixed> $countersMap */
        $countersMap = $counterKeys !== [] ? Cache::many(array_values($counterKeys)) : [];
        /** @var array<string, mixed> $totalsMap */
        $totalsMap = $totalsKeys !== [] ? Cache::many(array_values($totalsKeys)) : [];

        $nextCounters = [];
        $nextTotals = [];

        // Resolve any missing cached totals in a single batch DB query.
        $missingTotalsVmIds = [];
        foreach ($targetVms as $vm) {
            $tKey = "metrics:totals_latest:{$vm->id}";
            if (! isset($totalsMap[$tKey])) {
                $missingTotalsVmIds[] = $vm->id;
            }
        }

        if ($missingTotalsVmIds !== []) {
            $latestTotalsRows = DB::table('vm_metric_totals')
                ->whereIn('virtual_machine_id', array_values(array_unique($missingTotalsVmIds)))
                ->orderByDesc('recorded_at_ts')
                ->get(['virtual_machine_id', 'cpu_cores', 'ram_total_bytes', 'disk_total_bytes']);

            foreach ($latestTotalsRows as $row) {
                $vId = Cast::int($row->virtual_machine_id);
                $tKey = "metrics:totals_latest:{$vId}";
                if (! isset($totalsMap[$tKey])) {
                    $resolvedTotals = [
                        'cpu_cores' => Cast::int($row->cpu_cores),
                        'ram_total_bytes' => Cast::int($row->ram_total_bytes),
                        'disk_total_bytes' => Cast::int($row->disk_total_bytes),
                    ];
                    $totalsMap[$tKey] = $resolvedTotals;
                    $nextTotals[$tKey] = $resolvedTotals;
                }
            }
        }

        $sampleRows = [];
        $totalRows = [];

        foreach ($rawMap as $key => $dto) {
            if (! isset($targetVms[$key])) {
                continue;
            }

            $vm = $targetVms[$key];
            $cKey = "metrics:counters:{$vm->id}";
            $tKey = "metrics:totals_latest:{$vm->id}";

            $prior = Cast::arrayOrNull($countersMap[$cKey] ?? null);
            $effCpu = max(1, $vm->cpu_cores);

            $computed = $this->computeSampleFromDto(
                $dto,
                $effCpu,
                $now,
                $bucketTs,
                $interval,
                $prior,
                $vm->id,
            );

            $sampleRows[] = $computed['sample'];
            $nextCounters[$cKey] = $computed['next_counters'];

            $effRamTotal = $dto->memoryTotalBytes ?? ($vm->ram_mb * 1024 * 1024);
            $effDiskTotal = $dto->filesystemTotalBytes ?? ($vm->disk_gb * 1024 * 1024 * 1024);

            $cachedTotals = Cast::arrayOrNull($totalsMap[$tKey] ?? null);

            $totalsChanged = $cachedTotals === null
                || Cast::int($cachedTotals['cpu_cores'] ?? 0) !== $effCpu
                || Cast::int($cachedTotals['ram_total_bytes'] ?? 0) !== $effRamTotal
                || Cast::int($cachedTotals['disk_total_bytes'] ?? 0) !== $effDiskTotal;

            if ($totalsChanged) {
                $totalRows[] = [
                    'virtual_machine_id' => $vm->id,
                    'recorded_at_ts' => $bucketTs,
                    'cpu_cores' => $effCpu,
                    'ram_total_bytes' => $effRamTotal,
                    'disk_total_bytes' => $effDiskTotal,
                ];
                $nextTotals[$tKey] = [
                    'cpu_cores' => $effCpu,
                    'ram_total_bytes' => $effRamTotal,
                    'disk_total_bytes' => $effDiskTotal,
                ];
            }
        }

        if ($nextCounters !== []) {
            Cache::putMany($nextCounters, max(600, $interval * 4));
        }

        if ($nextTotals !== []) {
            Cache::putMany($nextTotals, 86400 * 7);
        }

        if ($sampleRows !== []) {
            usort($sampleRows, static fn (array $a, array $b): int => $a['virtual_machine_id'] <=> $b['virtual_machine_id']);
            DB::table('vm_metric_samples')->upsert(
                $sampleRows,
                ['virtual_machine_id', 'bucket_ts'],
                ['avail_mask', 'cpu_usage_bp', 'ram_used_bytes', 'disk_used_bytes', 'disk_read_bps', 'disk_write_bps', 'net_rx_bps', 'net_tx_bps'],
            );
        }

        if ($totalRows !== []) {
            usort($totalRows, static fn (array $a, array $b): int => $a['virtual_machine_id'] <=> $b['virtual_machine_id']);
            DB::table('vm_metric_totals')->upsert(
                $totalRows,
                ['virtual_machine_id', 'recorded_at_ts'],
                ['cpu_cores', 'ram_total_bytes', 'disk_total_bytes'],
            );
        }

        $durationMs = max(1, (int) round((microtime(true) - $t0) * 1000.0));
        Host::withoutEvents(static function () use ($host, $now, $durationMs): void {
            $host->metrics_last_success_at = Carbon::createFromTimestampUTC($now);
            $host->metrics_last_duration_ms = $durationMs;
            $host->metrics_consecutive_failures = 0;
            $host->metrics_backoff_until = null;
            $host->metrics_last_error = null;
            if (! ($host->status->equals(Unreachable::class) && $host->status->transitionIfAllowed(Ok::class))) {
                $host->save();
            }
        });

        return count($sampleRows);
    }

    /**
     * Record a host metrics collection failure, apply exponential backoff or pin status updates, and log warning.
     */
    public function recordHostFailure(Host $host, Throwable $e, int $durationMs, ?int $nowEpoch = null): void
    {
        $now = $nowEpoch ?? time();
        $nowCarbon = Carbon::createFromTimestampUTC($now);
        $cfg = $this->config ?? app(VmMetricsConfigService::class);
        $interval = $cfg->intervalSeconds();

        $rawMsg = preg_replace('/https?:\/\/[^\s]+/i', '[redacted-url]', $e->getMessage()) ?? $e->getMessage();
        $cleanMsg = substr(trim($rawMsg), 0, 500);
        $isPinError = $e instanceof FingerprintMismatchException || $e instanceof MissingPinException;
        $failures = $host->metrics_consecutive_failures + 1;

        Host::withoutEvents(static function () use ($host, $nowCarbon, $cleanMsg, $durationMs, $failures, $isPinError, $e, $interval): void {
            $host->metrics_last_error_at = $nowCarbon;
            $host->metrics_last_error = $cleanMsg !== '' ? $cleanMsg : 'Metrics collection failed.';
            $host->metrics_last_duration_ms = max(1, $durationMs);
            $host->metrics_consecutive_failures = $failures;

            if ($isPinError) {
                $targetState = $e instanceof FingerprintMismatchException ? FingerprintMismatch::class : NotTrusted::class;
                $host->metrics_backoff_until = null;
                if (! $host->status->transitionIfAllowed($targetState)) {
                    $host->save();
                }
            } else {
                $shift = min(10, max(0, $failures - 2));
                $backoffSec = $failures >= 2 ? min(900, $interval * (1 << $shift)) : 0;
                $host->metrics_backoff_until = $backoffSec > 0 ? $nowCarbon->copy()->addSeconds($backoffSec) : null;
                $host->save();
            }
        });

        Log::warning(sprintf(
            'Metrics collection failed for host [%s] (failures: %d): %s',
            $host->name,
            $failures,
            $host->metrics_last_error ?? 'Metrics collection failed.',
        ));
    }

    /**
     * Compute non-negative byte-per-second rate across a valid delta window, returning null on counter reset or gap.
     */
    private function computeByteRate(?int $current, mixed $prior, ?int $deltaT, bool $validWindow): ?int
    {
        if (! $validWindow || $deltaT === null || $deltaT <= 0 || $current === null || ! is_numeric($prior)) {
            return null;
        }

        $priorInt = (int) $prior;
        if ($current < $priorInt) {
            return null;
        }

        return (int) round((float) ($current - $priorInt) / (float) $deltaT);
    }
}
