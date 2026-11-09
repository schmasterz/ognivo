<?php

declare(strict_types=1);

namespace App\DTO\User;

use App\DTO\Cast;

/**
 * Immutable Data Transfer Object representing custom tenant quota boundaries.
 */
final readonly class CustomQuotaDTO
{
    public function __construct(
        public ?int $maxVms = null,
        public ?int $maxCpuCores = null,
        public ?int $maxRamMb = null,
        public ?int $maxStorageGb = null,
        public ?int $maxIps = null,
        public ?int $maxSnapshots = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            maxVms: Cast::intOrNull($data['max_vms'] ?? null),
            maxCpuCores: Cast::intOrNull($data['max_cpu_cores'] ?? null),
            maxRamMb: Cast::intOrNull($data['max_ram_mb'] ?? null),
            maxStorageGb: Cast::intOrNull($data['max_storage_gb'] ?? null),
            maxIps: Cast::intOrNull($data['max_ips'] ?? null),
            maxSnapshots: Cast::intOrNull($data['max_snapshots'] ?? null),
        );
    }

    /**
     * @return array{max_vms?: int, max_cpu_cores?: int, max_ram_mb?: int, max_storage_gb?: int, max_ips?: int, max_snapshots?: int}
     */
    public function toArray(): array
    {
        $result = [];
        if ($this->maxVms !== null) {
            $result['max_vms'] = $this->maxVms;
        }
        if ($this->maxCpuCores !== null) {
            $result['max_cpu_cores'] = $this->maxCpuCores;
        }
        if ($this->maxRamMb !== null) {
            $result['max_ram_mb'] = $this->maxRamMb;
        }
        if ($this->maxStorageGb !== null) {
            $result['max_storage_gb'] = $this->maxStorageGb;
        }
        if ($this->maxIps !== null) {
            $result['max_ips'] = $this->maxIps;
        }
        if ($this->maxSnapshots !== null) {
            $result['max_snapshots'] = $this->maxSnapshots;
        }

        return $result;
    }
}
