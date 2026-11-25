<?php

declare(strict_types=1);

use App\DTO\Incus\RawInstanceMetricsDTO;
use App\Services\Metrics\VmMetricsCollectorService;

function makeRawMetricsDto(
    ?float $cpuBusySecondsTotal = 100.0,
    ?int $memoryTotalBytes = 4_294_967_296,
    ?int $memoryAvailableBytes = 3_221_225_472,
    ?int $filesystemTotalBytes = 21_474_836_480,
    ?int $filesystemAvailBytes = 16_106_127_360,
    ?int $diskReadBytesTotal = 1_000_000,
    ?int $diskWriteBytesTotal = 2_000_000,
    ?int $netRxBytesTotal = 5_000_000,
    ?int $netTxBytesTotal = 3_000_000,
): RawInstanceMetricsDTO {
    return new RawInstanceMetricsDTO(
        project: 'default',
        name: 'vm-alpha',
        instanceType: 'virtual-machine',
        cpuBusySecondsTotal: $cpuBusySecondsTotal,
        effectiveCpus: 2,
        memoryTotalBytes: $memoryTotalBytes,
        memoryAvailableBytes: $memoryAvailableBytes,
        filesystemTotalBytes: $filesystemTotalBytes,
        filesystemAvailBytes: $filesystemAvailBytes,
        diskReadBytesTotal: $diskReadBytesTotal,
        diskWriteBytesTotal: $diskWriteBytesTotal,
        netRxBytesTotal: $netRxBytesTotal,
        netTxBytesTotal: $netTxBytesTotal,
        procsTotal: 42,
    );
}

it('populates gauges and sets all five rate columns to null on the first cycle', function (): void {
    $collector = new VmMetricsCollectorService;
    $dto = makeRawMetricsDto();

    $result = $collector->computeSampleFromDto(
        dto: $dto,
        cpuCores: 2,
        nowEpoch: 1_700_000_060,
        bucketTs: 1_700_000_060,
        intervalSeconds: 60,
        priorCounters: null,
        virtualMachineId: 10,
    );

    expect($result['sample'])->toBe([
        'virtual_machine_id' => 10,
        'bucket_ts' => 1_700_000_060,
        'avail_mask' => VmMetricsCollectorService::MASK_ALL,
        'cpu_usage_bp' => null,
        'ram_used_bytes' => 1_073_741_824,
        'disk_used_bytes' => 5_368_709_120,
        'disk_read_bps' => null,
        'disk_write_bps' => null,
        'net_rx_bps' => null,
        'net_tx_bps' => null,
    ])
        ->and($result['next_counters']['ts'])->toBe(1_700_000_060)
        ->and($result['next_counters']['bucket_ts'])->toBe(1_700_000_060)
        ->and($result['next_counters']['prev'])->toBeNull();
});

it('computes exact CPU basis points and byte rates on the second cycle', function (): void {
    $collector = new VmMetricsCollectorService;
    $first = $collector->computeSampleFromDto(
        dto: makeRawMetricsDto(),
        cpuCores: 2,
        nowEpoch: 1_700_000_060,
        bucketTs: 1_700_000_060,
        intervalSeconds: 60,
        priorCounters: null,
        virtualMachineId: 10,
    );

    // 2 cores * 60s = 120s capacity; +30s busy => 25.00% => 2500 bp
    $secondDto = makeRawMetricsDto(
        cpuBusySecondsTotal: 130.0,
        diskReadBytesTotal: 1_060_000,   // +60_000 B / 60s = 1000 B/s
        diskWriteBytesTotal: 2_120_000,  // +120_000 B / 60s = 2000 B/s
        netRxBytesTotal: 5_300_000,      // +300_000 B / 60s = 5000 B/s
        netTxBytesTotal: 3_180_000,      // +180_000 B / 60s = 3000 B/s
    );

    $second = $collector->computeSampleFromDto(
        dto: $secondDto,
        cpuCores: 2,
        nowEpoch: 1_700_000_120,
        bucketTs: 1_700_000_120,
        intervalSeconds: 60,
        priorCounters: $first['next_counters'],
        virtualMachineId: 10,
    );

    expect($second['sample'])->toBe([
        'virtual_machine_id' => 10,
        'bucket_ts' => 1_700_000_120,
        'avail_mask' => 31,
        'cpu_usage_bp' => 2500,
        'ram_used_bytes' => 1_073_741_824,
        'disk_used_bytes' => 5_368_709_120,
        'disk_read_bps' => 1000,
        'disk_write_bps' => 2000,
        'net_rx_bps' => 5000,
        'net_tx_bps' => 3000,
    ]);
});

it('returns null rates and saves post-reboot counters when cumulative counters reset', function (): void {
    $collector = new VmMetricsCollectorService;
    $first = $collector->computeSampleFromDto(
        dto: makeRawMetricsDto(),
        cpuCores: 2,
        nowEpoch: 1_700_000_060,
        bucketTs: 1_700_000_060,
        intervalSeconds: 60,
        priorCounters: null,
        virtualMachineId: 10,
    );

    $postRebootDto = makeRawMetricsDto(
        cpuBusySecondsTotal: 4.5,
        diskReadBytesTotal: 12_000,
        diskWriteBytesTotal: 24_000,
        netRxBytesTotal: 50_000,
        netTxBytesTotal: 30_000,
    );

    $second = $collector->computeSampleFromDto(
        dto: $postRebootDto,
        cpuCores: 2,
        nowEpoch: 1_700_000_120,
        bucketTs: 1_700_000_120,
        intervalSeconds: 60,
        priorCounters: $first['next_counters'],
        virtualMachineId: 10,
    );

    expect($second['sample']['cpu_usage_bp'])->toBeNull()
        ->and($second['sample']['disk_read_bps'])->toBeNull()
        ->and($second['sample']['disk_write_bps'])->toBeNull()
        ->and($second['sample']['net_rx_bps'])->toBeNull()
        ->and($second['sample']['net_tx_bps'])->toBeNull()
        ->and($second['next_counters']['cpu_busy_sec'])->toBe(4.5)
        ->and($second['next_counters']['disk_read_bytes'])->toBe(12_000)
        ->and($second['next_counters']['disk_write_bytes'])->toBe(24_000)
        ->and($second['next_counters']['net_rx_bytes'])->toBe(50_000)
        ->and($second['next_counters']['net_tx_bytes'])->toBe(30_000);
});

it('discards all rate columns as null when delta time exceeds 2.5x interval gap rule', function (): void {
    $collector = new VmMetricsCollectorService;
    $first = $collector->computeSampleFromDto(
        dto: makeRawMetricsDto(),
        cpuCores: 2,
        nowEpoch: 1_700_000_060,
        bucketTs: 1_700_000_060,
        intervalSeconds: 60,
        priorCounters: null,
        virtualMachineId: 10,
    );

    // 2.5 * 60 = 150s max gap; 151s exceeds the gap threshold.
    $afterGap = $collector->computeSampleFromDto(
        dto: makeRawMetricsDto(
            cpuBusySecondsTotal: 150.0,
            diskReadBytesTotal: 1_500_000,
            diskWriteBytesTotal: 2_500_000,
            netRxBytesTotal: 5_500_000,
            netTxBytesTotal: 3_500_000,
        ),
        cpuCores: 2,
        nowEpoch: 1_700_000_060 + 151,
        bucketTs: 1_700_000_180,
        intervalSeconds: 60,
        priorCounters: $first['next_counters'],
        virtualMachineId: 10,
    );

    expect($afterGap['sample']['cpu_usage_bp'])->toBeNull()
        ->and($afterGap['sample']['disk_read_bps'])->toBeNull()
        ->and($afterGap['sample']['disk_write_bps'])->toBeNull()
        ->and($afterGap['sample']['net_rx_bps'])->toBeNull()
        ->and($afterGap['sample']['net_tx_bps'])->toBeNull()
        ->and($afterGap['sample']['ram_used_bytes'])->toBe(1_073_741_824)
        ->and($afterGap['sample']['disk_used_bytes'])->toBe(5_368_709_120);
});

it('clamps CPU overshoot up to 105 percent to 10000 bp and discards values above 105 percent', function (): void {
    $collector = new VmMetricsCollectorService;
    $first = $collector->computeSampleFromDto(
        dto: makeRawMetricsDto(cpuBusySecondsTotal: 100.0),
        cpuCores: 2,
        nowEpoch: 1_700_000_060,
        bucketTs: 1_700_000_060,
        intervalSeconds: 60,
        priorCounters: null,
        virtualMachineId: 10,
    );

    // 103.4% across 2 cores * 60s = 124.08s delta => clamped to 10000 bp
    $overshoot = $collector->computeSampleFromDto(
        dto: makeRawMetricsDto(cpuBusySecondsTotal: 224.08),
        cpuCores: 2,
        nowEpoch: 1_700_000_120,
        bucketTs: 1_700_000_120,
        intervalSeconds: 60,
        priorCounters: $first['next_counters'],
        virtualMachineId: 10,
    );

    // 115.0% across 2 cores * 60s = 138.0s delta => discarded as null
    $implausible = $collector->computeSampleFromDto(
        dto: makeRawMetricsDto(cpuBusySecondsTotal: 238.0),
        cpuCores: 2,
        nowEpoch: 1_700_000_120,
        bucketTs: 1_700_000_120,
        intervalSeconds: 60,
        priorCounters: $first['next_counters'],
        virtualMachineId: 10,
    );

    expect($overshoot['sample']['cpu_usage_bp'])->toBe(10_000)
        ->and($implausible['sample']['cpu_usage_bp'])->toBeNull();
});

it('encodes avail_mask 25 and null RAM/disk gauges when guest agent metrics are absent', function (): void {
    $collector = new VmMetricsCollectorService;
    $first = $collector->computeSampleFromDto(
        dto: makeRawMetricsDto(
            memoryTotalBytes: null,
            memoryAvailableBytes: null,
            filesystemTotalBytes: null,
            filesystemAvailBytes: null,
        ),
        cpuCores: 2,
        nowEpoch: 1_700_000_060,
        bucketTs: 1_700_000_060,
        intervalSeconds: 60,
        priorCounters: null,
        virtualMachineId: 10,
    );

    $second = $collector->computeSampleFromDto(
        dto: makeRawMetricsDto(
            cpuBusySecondsTotal: 160.0, // +60s / (2 * 60s) = 50% => 5000 bp
            memoryTotalBytes: null,
            memoryAvailableBytes: null,
            filesystemTotalBytes: null,
            filesystemAvailBytes: null,
            diskReadBytesTotal: 1_060_000,
            diskWriteBytesTotal: 2_120_000,
            netRxBytesTotal: 5_300_000,
            netTxBytesTotal: 3_180_000,
        ),
        cpuCores: 2,
        nowEpoch: 1_700_000_120,
        bucketTs: 1_700_000_120,
        intervalSeconds: 60,
        priorCounters: $first['next_counters'],
        virtualMachineId: 10,
    );

    expect($second['sample'])->toBe([
        'virtual_machine_id' => 10,
        'bucket_ts' => 1_700_000_120,
        'avail_mask' => 25,
        'cpu_usage_bp' => 5000,
        'ram_used_bytes' => null,
        'disk_used_bytes' => null,
        'disk_read_bps' => 1000,
        'disk_write_bps' => 2000,
        'net_rx_bps' => 5000,
        'net_tx_bps' => 3000,
    ]);
});

it('produces identical rates on duplicate concurrent run for the same bucket_ts', function (): void {
    $collector = new VmMetricsCollectorService;

    $baseline = $collector->computeSampleFromDto(
        dto: makeRawMetricsDto(),
        cpuCores: 2,
        nowEpoch: 1_700_000_060,
        bucketTs: 1_700_000_060,
        intervalSeconds: 60,
        priorCounters: null,
        virtualMachineId: 10,
    );

    $currentDto = makeRawMetricsDto(
        cpuBusySecondsTotal: 130.0,
        diskReadBytesTotal: 1_060_000,
        diskWriteBytesTotal: 2_120_000,
        netRxBytesTotal: 5_300_000,
        netTxBytesTotal: 3_180_000,
    );

    $firstRunForBucket = $collector->computeSampleFromDto(
        dto: $currentDto,
        cpuCores: 2,
        nowEpoch: 1_700_000_120,
        bucketTs: 1_700_000_120,
        intervalSeconds: 60,
        priorCounters: $baseline['next_counters'],
        virtualMachineId: 10,
    );

    $duplicateRunForBucket = $collector->computeSampleFromDto(
        dto: $currentDto,
        cpuCores: 2,
        nowEpoch: 1_700_000_120,
        bucketTs: 1_700_000_120,
        intervalSeconds: 60,
        priorCounters: $firstRunForBucket['next_counters'],
        virtualMachineId: 10,
    );

    expect($duplicateRunForBucket['sample'])->toBe($firstRunForBucket['sample']);
});
