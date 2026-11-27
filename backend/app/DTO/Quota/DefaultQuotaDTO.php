<?php

declare(strict_types=1);

namespace App\DTO\Quota;

use App\Support\Cast;

/**
 * Immutable Data Transfer Object for system-wide default quota boundaries.
 */
final readonly class DefaultQuotaDTO
{
    public function __construct(
        public int $maxVms,
        public int $maxCpuCores,
        public int $maxRamMb,
        public int $maxStorageGb,
        public int $maxIps,
        public int $maxSnapshots = 3,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            maxVms: Cast::int($data['max_vms'] ?? 0),
            maxCpuCores: Cast::int($data['max_cpu_cores'] ?? 0),
            maxRamMb: Cast::int($data['max_ram_mb'] ?? 0),
            maxStorageGb: Cast::int($data['max_storage_gb'] ?? 0),
            maxIps: Cast::int($data['max_ips'] ?? 0),
            maxSnapshots: array_key_exists('max_snapshots', $data) && $data['max_snapshots'] !== null
                ? Cast::int($data['max_snapshots'], 3)
                : 3,
        );
    }

    /**
     * @return array{max_vms: int, max_cpu_cores: int, max_ram_mb: int, max_storage_gb: int, max_ips: int, max_snapshots: int}
     */
    public function toArray(): array
    {
        return [
            'max_vms' => $this->maxVms,
            'max_cpu_cores' => $this->maxCpuCores,
            'max_ram_mb' => $this->maxRamMb,
            'max_storage_gb' => $this->maxStorageGb,
            'max_ips' => $this->maxIps,
            'max_snapshots' => $this->maxSnapshots,
        ];
    }
}
