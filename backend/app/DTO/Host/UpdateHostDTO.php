<?php

declare(strict_types=1);

namespace App\DTO\Host;

use App\Support\Cast;
use App\ValueObjects\Image\ImageSelection;

/**
 * Immutable Data Transfer Object for updating an existing host node.
 */
final readonly class UpdateHostDTO
{
    /**
     * @param  array<string, mixed>|null  $settings
     */
    public function __construct(
        public string $name,
        public string $endpointUrl,
        public ?string $region = null,
        public ?string $driver = null,
        public ?string $token = null,
        public bool $hasTokenUpdate = false,
        public ?bool $isActive = null,
        public ?int $totalCpuCores = null,
        public ?int $totalRamMb = null,
        public ?int $totalStorageGb = null,
        public ?array $settings = null,
        public ?string $serverCertificate = null,
        public bool $hasCertificateUpdate = false,
        public ?string $serverFingerprint = null,
        public ?string $serverPublicKeyPin = null,
        public ?bool $autoAttachImages = null,
        public ?ImageSelection $images = null,
        public ?float $cpuOvercommit = null,
        public ?float $ramOvercommit = null,
        public ?float $diskOvercommit = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $images = Cast::arrayOrNull($data['images'] ?? null);

        return new self(
            name: Cast::string($data['name'] ?? ''),
            endpointUrl: Cast::string($data['endpoint_url'] ?? ''),
            region: Cast::stringOrNull($data['region'] ?? null),
            driver: Cast::stringOrNull($data['driver'] ?? null),
            token: array_key_exists('token', $data) ? Cast::stringOrNull($data['token']) : null,
            hasTokenUpdate: array_key_exists('token', $data),
            isActive: Cast::boolOrNull($data['is_active'] ?? null),
            totalCpuCores: Cast::intOrNull($data['total_cpu_cores'] ?? null),
            totalRamMb: Cast::intOrNull($data['total_ram_mb'] ?? null),
            totalStorageGb: Cast::intOrNull($data['total_storage_gb'] ?? null),
            settings: Cast::arrayOrNull($data['settings'] ?? null),
            serverCertificate: array_key_exists('server_certificate', $data) ? Cast::stringOrNull($data['server_certificate']) : null,
            hasCertificateUpdate: array_key_exists('server_certificate', $data),
            serverFingerprint: Cast::stringOrNull($data['server_fingerprint'] ?? $data['expected_fingerprint'] ?? null),
            serverPublicKeyPin: Cast::stringOrNull($data['server_public_key_pin'] ?? null),
            autoAttachImages: Cast::boolOrNull($data['auto_attach_images'] ?? null),
            images: $images !== null ? ImageSelection::fromArray($images) : null,
            cpuOvercommit: isset($data['cpu_overcommit']) && is_numeric($data['cpu_overcommit']) ? (float) $data['cpu_overcommit'] : null,
            ramOvercommit: isset($data['ram_overcommit']) && is_numeric($data['ram_overcommit']) ? (float) $data['ram_overcommit'] : null,
            diskOvercommit: isset($data['disk_overcommit']) && is_numeric($data['disk_overcommit']) ? (float) $data['disk_overcommit'] : null,
        );
    }
}
