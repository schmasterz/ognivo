<?php

declare(strict_types=1);

namespace App\DTO\Host;

/**
 * Data Transfer Object representing compute and storage resources discovered from a hypervisor host.
 */
final readonly class HostResourcesDTO
{
    public function __construct(
        public int $cpuCores,
        public int $ramMb,
        public int $storageGb,
        public string $storagePool = 'default',
    ) {}

    /**
     * @return array{cpu_cores: int, ram_mb: int, storage_gb: int, storage_pool: string}
     */
    public function toArray(): array
    {
        return [
            'cpu_cores' => $this->cpuCores,
            'ram_mb' => $this->ramMb,
            'storage_gb' => $this->storageGb,
            'storage_pool' => $this->storagePool,
        ];
    }
}
