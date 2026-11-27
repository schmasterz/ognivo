<?php

declare(strict_types=1);

namespace App\DTO\Hypervisor;

/**
 * Immutable DTO holding raw cumulative counters and gauges parsed from Incus/LXD /1.0/metrics for one instance.
 */
final readonly class RawInstanceMetricsDTO
{
    public function __construct(
        public string $project,
        public string $name,
        public string $instanceType,
        public ?float $cpuBusySecondsTotal,
        public int $effectiveCpus,
        public ?int $memoryTotalBytes,
        public ?int $memoryAvailableBytes,
        public ?int $filesystemTotalBytes,
        public ?int $filesystemAvailBytes,
        public ?int $diskReadBytesTotal,
        public ?int $diskWriteBytesTotal,
        public ?int $netRxBytesTotal,
        public ?int $netTxBytesTotal,
        public int $procsTotal,
    ) {}
}
