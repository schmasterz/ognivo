<?php

declare(strict_types=1);

namespace App\DTO\Vm;

use App\DTO\Cast;

/**
 * Immutable Data Transfer Object for provisioning a virtual machine or container.
 */
final readonly class CreateVmDTO
{
    /**
     * @param  array<string, mixed>|null  $cloudInit
     */
    public function __construct(
        public string $name,
        public int $cpuCores,
        public int $ramMb,
        public int $diskGb,
        public string $osImage,
        public string $instanceType = 'virtual-machine',
        public ?int $hostId = null,
        public ?array $cloudInit = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed>|null $cloudInit */
        $cloudInit = isset($data['cloud_init']) && is_array($data['cloud_init']) ? $data['cloud_init'] : null;

        return new self(
            name: Cast::string($data['name'] ?? ''),
            cpuCores: Cast::int($data['cpu_cores'] ?? 1, 1),
            ramMb: Cast::int($data['ram_mb'] ?? 512, 512),
            diskGb: Cast::int($data['disk_gb'] ?? 10, 10),
            osImage: Cast::string($data['os_image'] ?? ''),
            instanceType: Cast::string($data['instance_type'] ?? 'virtual-machine', 'virtual-machine'),
            hostId: Cast::intOrNull($data['host_id'] ?? null),
            cloudInit: $cloudInit,
        );
    }

    /**
     * @return array{name: string, cpu_cores: int, ram_mb: int, disk_gb: int, os_image: string, instance_type: string, host_id: int|null, cloud_init: array<string, mixed>|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'cpu_cores' => $this->cpuCores,
            'ram_mb' => $this->ramMb,
            'disk_gb' => $this->diskGb,
            'os_image' => $this->osImage,
            'instance_type' => $this->instanceType,
            'host_id' => $this->hostId,
            'cloud_init' => $this->cloudInit,
        ];
    }
}
