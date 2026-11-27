<?php

declare(strict_types=1);

namespace App\DTO\Quota;

use App\Support\Cast;

/**
 * Immutable Data Transfer Object for updating an existing Quota Group.
 */
final readonly class UpdateQuotaGroupDTO
{
    public function __construct(
        public string $name,
        public int $maxVms,
        public int $maxCpuCores,
        public int $maxRamMb,
        public int $maxStorageGb,
        public int $maxIps,
        public ?string $description = null,
        public bool $isDefault = false,
        public ?int $maxSnapshots = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: Cast::string($data['name'] ?? ''),
            maxVms: Cast::int($data['max_vms'] ?? 0),
            maxCpuCores: Cast::int($data['max_cpu_cores'] ?? 0),
            maxRamMb: Cast::int($data['max_ram_mb'] ?? 0),
            maxStorageGb: Cast::int($data['max_storage_gb'] ?? 0),
            maxIps: Cast::int($data['max_ips'] ?? 0),
            description: Cast::stringOrNull($data['description'] ?? null),
            isDefault: Cast::bool($data['is_default'] ?? false),
            maxSnapshots: Cast::intOrNull($data['max_snapshots'] ?? null),
        );
    }
}
