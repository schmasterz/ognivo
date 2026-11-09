<?php

declare(strict_types=1);

namespace App\DTO\Host;

use App\DTO\Cast;

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
        public int $totalCpuCores = 32,
        public int $totalRamMb = 65536,
        public int $totalStorageGb = 2000,
        public ?array $settings = null,
        public ?string $serverCertificate = null,
        public ?string $serverFingerprint = null,
        public ?string $serverPublicKeyPin = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $allowFake = app()->environment(['local', 'testing']) || (bool) config('app.allow_fake_driver');
        $defaultDriver = $allowFake ? 'fake' : 'incus';

        return new self(
            name: Cast::string($data['name'] ?? ''),
            endpointUrl: Cast::string($data['endpoint_url'] ?? ''),
            region: Cast::stringOrNull($data['region'] ?? null),
            driver: Cast::string($data['driver'] ?? $defaultDriver, $defaultDriver),
            token: Cast::stringOrNull($data['token'] ?? null),
            isActive: Cast::bool($data['is_active'] ?? true, true),
            totalCpuCores: Cast::int($data['total_cpu_cores'] ?? 32, 32),
            totalRamMb: Cast::int($data['total_ram_mb'] ?? 65536, 65536),
            totalStorageGb: Cast::int($data['total_storage_gb'] ?? 2000, 2000),
            settings: Cast::arrayOrNull($data['settings'] ?? null),
            serverCertificate: Cast::stringOrNull($data['server_certificate'] ?? null),
            serverFingerprint: Cast::stringOrNull($data['server_fingerprint'] ?? $data['expected_fingerprint'] ?? null),
            serverPublicKeyPin: Cast::stringOrNull($data['server_public_key_pin'] ?? null),
        );
    }
}
