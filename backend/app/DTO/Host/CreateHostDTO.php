<?php

declare(strict_types=1);

namespace App\DTO\Host;

use App\Support\Cast;
use App\ValueObjects\Image\ImageSelection;

/**
 * Immutable Data Transfer Object for creating an Incus / LXD host node.
 */
final readonly class CreateHostDTO
{
    /**
     * @param  array<string, mixed>|null  $settings
     */
    public function __construct(
        public string $name,
        public string $endpointUrl,
        public ?string $region = null,
        public string $driver = 'fake',
        public ?string $token = null,
        public bool $isActive = true,
        public int $totalCpuCores = 0,
        public int $totalRamMb = 0,
        public int $totalStorageGb = 0,
        public ?array $settings = null,
        public ?string $serverCertificate = null,
        public ?string $serverFingerprint = null,
        public ?string $serverPublicKeyPin = null,
        public bool $autoAttachImages = true,
        public ?ImageSelection $images = null,
        public float $cpuOvercommit = 1.0,
        public float $ramOvercommit = 1.0,
        public float $diskOvercommit = 1.0,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $defaultDriver = 'incus'): self
    {
        $images = Cast::arrayOrNull($data['images'] ?? null);

        return new self(
            name: Cast::string($data['name'] ?? ''),
            endpointUrl: Cast::string($data['endpoint_url'] ?? ''),
            region: Cast::stringOrNull($data['region'] ?? null),
            driver: Cast::string($data['driver'] ?? $defaultDriver, $defaultDriver),
            token: Cast::stringOrNull($data['token'] ?? null),
            isActive: Cast::bool($data['is_active'] ?? true, true),
            totalCpuCores: Cast::int($data['total_cpu_cores'] ?? 0, 0),
            totalRamMb: Cast::int($data['total_ram_mb'] ?? 0, 0),
            totalStorageGb: Cast::int($data['total_storage_gb'] ?? 0, 0),
            settings: Cast::arrayOrNull($data['settings'] ?? null),
            serverCertificate: Cast::stringOrNull($data['server_certificate'] ?? null),
            serverFingerprint: Cast::stringOrNull($data['server_fingerprint'] ?? $data['expected_fingerprint'] ?? null),
            serverPublicKeyPin: Cast::stringOrNull($data['server_public_key_pin'] ?? null),
            autoAttachImages: Cast::bool($data['auto_attach_images'] ?? true, true),
            images: $images !== null ? ImageSelection::fromArray($images) : null,
            cpuOvercommit: Cast::float($data['cpu_overcommit'] ?? 1.0, 1.0),
            ramOvercommit: Cast::float($data['ram_overcommit'] ?? 1.0, 1.0),
            diskOvercommit: Cast::float($data['disk_overcommit'] ?? 1.0, 1.0),
        );
    }
}
