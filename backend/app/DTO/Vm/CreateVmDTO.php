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
        public ?int $cpuCores = null,
        public ?int $ramMb = null,
        public ?int $diskGb = null,
        public string $osImage = '',
        public ?string $instanceType = null,
        public ?int $hostId = null,
        public ?array $cloudInit = null,
        public ?int $vpsPlanId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed>|null $cloudInit */
        $cloudInit = isset($data['cloud_init']) && is_array($data['cloud_init']) ? $data['cloud_init'] : null;

        $instanceType = isset($data['instance_type']) && is_string($data['instance_type']) && $data['instance_type'] !== ''
            ? $data['instance_type']
            : null;

        return new self(
            name: Cast::string($data['name'] ?? ''),
            cpuCores: Cast::intOrNull($data['cpu_cores'] ?? null),
            ramMb: Cast::intOrNull($data['ram_mb'] ?? null),
            diskGb: Cast::intOrNull($data['disk_gb'] ?? null),
            osImage: Cast::string($data['os_image'] ?? ''),
            instanceType: $instanceType,
            hostId: Cast::intOrNull($data['host_id'] ?? null),
            cloudInit: $cloudInit,
            vpsPlanId: Cast::intOrNull($data['vps_plan_id'] ?? null),
        );
    }

    /**
     * @return array{name: string, cpu_cores: int|null, ram_mb: int|null, disk_gb: int|null, os_image: string, instance_type: string|null, host_id: int|null, cloud_init: array<string, mixed>|null, vps_plan_id: int|null}
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
            'vps_plan_id' => $this->vpsPlanId,
        ];
    }
}
