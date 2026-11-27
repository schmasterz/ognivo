<?php

declare(strict_types=1);

use App\DTO\Hypervisor\RawInstanceMetricsDTO;
use App\Models\Host;
use App\Models\VirtualMachine;
use App\Services\Hypervisor\Drivers\FakeHypervisorDriver;
use App\Services\Hypervisor\HypervisorManager;
use App\Services\Metrics\VmMetricsCollectorService;
use App\Services\Metrics\VmMetricsRollupService;
use App\States\Vm\Running;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

afterEach(function (): void {
    HypervisorManager::resetFake();
});

it('recovers 5m rollup start timestamp from database max bucket_ts when redis cursor is missing', function (): void {
    $host = Host::factory()->create([
        'driver' => 'fake',
        'is_active' => true,
        'status' => 'ok',
    ]);

    $vm = VirtualMachine::factory()->create([
        'host_id' => $host->id,
        'status' => Running::class,
    ]);

    $now = time();
    $closedEnd = intdiv($now, 300) * 300;
    $existingRollupTs = intdiv($now - 3600, 300) * 300;
    $sampleTs = $existingRollupTs + 300;

    DB::table('vm_metric_rollups_5m')->insert([
        'virtual_machine_id' => $vm->id,
        'bucket_ts' => $existingRollupTs,
        'sample_count' => 5,
        'avail_mask' => 31,
        'cpu_avg_bp' => 1500,
        'cpu_min_bp' => 1000,
        'cpu_max_bp' => 2000,
    ]);

    DB::table('vm_metric_samples')->insert([
        'virtual_machine_id' => $vm->id,
        'bucket_ts' => $sampleTs,
        'avail_mask' => 31,
        'cpu_usage_bp' => 2500,
        'ram_used_bytes' => 1000000,
        'disk_used_bytes' => 2000000,
        'disk_read_bps' => 10000,
        'disk_write_bps' => 5000,
        'net_rx_bps' => 2500,
        'net_tx_bps' => 1250,
    ]);

    Cache::forget('metrics:rollup:last_5m_ts');

    app(VmMetricsRollupService::class)->rollupFiveMinuteBuckets($now);

    $rolledRow = DB::table('vm_metric_rollups_5m')
        ->where('virtual_machine_id', $vm->id)
        ->where('bucket_ts', $sampleTs)
        ->first();

    expect($rolledRow)->not->toBeNull()
        ->and((int) $rolledRow->sample_count)->toBe(1)
        ->and((int) $rolledRow->avail_mask)->toBe(31)
        ->and((int) $rolledRow->cpu_avg_bp)->toBe(2500)
        ->and((int) $rolledRow->ram_avg_bytes)->toBe(1000000)
        ->and((int) $rolledRow->disk_avg_bytes)->toBe(2000000)
        ->and(Cache::get('metrics:rollup:last_5m_ts'))->toBe($closedEnd);
});

it('chunks large historical backlog into bounded time windows without skipping samples', function (): void {
    $host = Host::factory()->create([
        'driver' => 'fake',
        'is_active' => true,
        'status' => 'ok',
    ]);

    $vm = VirtualMachine::factory()->create([
        'host_id' => $host->id,
        'status' => Running::class,
    ]);

    $now = time();
    $closedEnd = intdiv($now, 300) * 300;
    $bucketCount = 36; // 3 hours of 5-minute buckets
    $startBucket = $closedEnd - ($bucketCount * 300);

    $samples = [];
    for ($i = 0; $i < $bucketCount; $i++) {
        $samples[] = [
            'virtual_machine_id' => $vm->id,
            'bucket_ts' => $startBucket + ($i * 300),
            'avail_mask' => 31,
            'cpu_usage_bp' => 2000 + ($i * 10),
            'ram_used_bytes' => 1000000 + ($i * 1000),
            'disk_used_bytes' => 2000000 + ($i * 1000),
            'disk_read_bps' => 10000,
            'disk_write_bps' => 5000,
            'net_rx_bps' => 2500,
            'net_tx_bps' => 1250,
        ];
    }

    DB::table('vm_metric_samples')->insert($samples);
    Cache::forget('metrics:rollup:last_5m_ts');

    app(VmMetricsRollupService::class)->rollupFiveMinuteBuckets($now);

    expect(DB::table('vm_metric_rollups_5m')->where('virtual_machine_id', $vm->id)->count())
        ->toBe($bucketCount);
});

it('collects metrics in batch and updates counters and totals via Cache::putMany', function (): void {
    config([
        'metrics.enabled' => true,
        'metrics.interval_seconds' => 60,
    ]);

    $fakeDriver = new FakeHypervisorDriver;
    HypervisorManager::fake($fakeDriver);

    $host = Host::factory()->create([
        'driver' => 'fake',
        'is_active' => true,
        'status' => 'ok',
    ]);

    $vms = collect([1, 2, 3])->map(fn (int $index): VirtualMachine => VirtualMachine::factory()->create([
        'host_id' => $host->id,
        'incus_project' => 'default',
        'name' => "batch-vm-{$index}",
        'status' => Running::class,
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 20,
    ]));

    $dtos = [];
    foreach ($vms as $index => $vm) {
        Cache::forget("metrics:counters:{$vm->id}");
        Cache::forget("metrics:totals_latest:{$vm->id}");

        $offset = $index + 1;
        $dtos["default/{$vm->name}"] = new RawInstanceMetricsDTO(
            project: 'default',
            name: $vm->name,
            instanceType: 'virtual-machine',
            cpuBusySecondsTotal: 10.0 * $offset,
            effectiveCpus: 2,
            memoryTotalBytes: 2147483648,
            memoryAvailableBytes: 1073741824,
            filesystemTotalBytes: 21474836480,
            filesystemAvailBytes: 16106127360,
            diskReadBytesTotal: 100000 * $offset,
            diskWriteBytesTotal: 50000 * $offset,
            netRxBytesTotal: 20000 * $offset,
            netTxBytesTotal: 10000 * $offset,
            procsTotal: 42,
        );
    }

    $fakeDriver->setHostMetrics($host, $dtos);

    $collectedCount = app(VmMetricsCollectorService::class)->collectForHost($host, 1795560000);

    expect($collectedCount)->toBe(3);

    foreach ($vms as $vm) {
        expect(DB::table('vm_metric_samples')->where('virtual_machine_id', $vm->id)->count())->toBe(1)
            ->and(DB::table('vm_metric_totals')->where('virtual_machine_id', $vm->id)->count())->toBe(1)
            ->and(Cache::get("metrics:counters:{$vm->id}"))->not->toBeNull()
            ->and(Cache::get("metrics:totals_latest:{$vm->id}"))->not->toBeNull();
    }
});
