<?php

declare(strict_types=1);

namespace App\Services\Hypervisor;

use App\DTO\Cast;
use App\DTO\Hypervisor\InstanceResourceSpec;
use App\DTO\Hypervisor\NetworkLimitsSpec;
use DomainException;
use InvalidArgumentException;

/**
 * Pure parser and patch builder for Incus/LXD instance resource specifications and PUT payloads.
 */
final class InstanceConfigPatchBuilder
{
    private const int BYTES_PER_MIB = 1024 * 1024;

    private const int BYTES_PER_GIB = 1024 * 1024 * 1024;

    /**
     * Size multipliers in bytes keyed by lowercase unit suffix.
     *
     * @var array<string, int>
     */
    private const array SIZE_UNIT_MULTIPLIERS = [
        'b' => 1,
        'kb' => 1000,
        'mb' => 1000 ** 2,
        'gb' => 1000 ** 3,
        'tb' => 1000 ** 4,
        'kib' => 1024,
        'mib' => 1024 ** 2,
        'gib' => 1024 ** 3,
        'tib' => 1024 ** 4,
    ];

    /**
     * Parse an Incus/LXD instance metadata payload into a normalized InstanceResourceSpec.
     *
     * @param  array<string, mixed>  $instanceData
     */
    public function parseSpec(array $instanceData): InstanceResourceSpec
    {
        $rawType = Cast::string($instanceData['type'] ?? '');
        $instanceType = $rawType === 'container' ? 'container' : 'virtual-machine';

        $expandedConfig = Cast::arrayOrNull($instanceData['expanded_config'] ?? null) ?? [];
        $localConfig = Cast::arrayOrNull($instanceData['config'] ?? null) ?? [];
        /** @var array<string, mixed> $config */
        $config = array_merge($expandedConfig, $localConfig);

        $cpuCores = $this->parseCpuCount($config['limits.cpu'] ?? null);
        $ramMb = $this->parseMemoryMb($config['limits.memory'] ?? null);

        $rootDisk = $this->findRootDiskDevice($instanceData);
        $diskGb = $rootDisk !== null
            ? $this->parseDiskGb($rootDisk['device']['size'] ?? null)
            : 0;

        $localNic = $this->findLocalNicDevice($instanceData);
        $network = null;
        if ($localNic !== null) {
            $downloadMbps = $this->parseBandwidthMbps($localNic['device'][NicDeviceBuilder::LIMIT_KEYS['download']] ?? null);
            $uploadMbps = $this->parseBandwidthMbps($localNic['device'][NicDeviceBuilder::LIMIT_KEYS['upload']] ?? null);

            if ($downloadMbps !== null || $uploadMbps !== null) {
                $network = new NetworkLimitsSpec(
                    downloadMbps: $downloadMbps,
                    uploadMbps: $uploadMbps,
                );
            }
        }

        return new InstanceResourceSpec(
            cpuCores: $cpuCores,
            ramMb: $ramMb,
            diskGb: $diskGb,
            instanceType: $instanceType,
            network: $network,
        );
    }

    /**
     * Clone an existing Incus/LXD instance payload and apply target resource updates
     * while preserving all unrelated config keys and device attributes.
     *
     * @param  array<string, mixed>  $currentInstance
     * @return array<string, mixed>
     */
    public function buildPutPayload(array $currentInstance, InstanceResourceSpec $target): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $currentInstance;

        $config = Cast::arrayOrNull($payload['config'] ?? null) ?? [];
        $config['limits.cpu'] = (string) $target->cpuCores;
        $config['limits.memory'] = sprintf('%dMiB', $target->ramMb);
        $payload['config'] = $config;

        $devices = Cast::arrayOrNull($payload['devices'] ?? null) ?? [];
        $rootDisk = $this->findRootDiskDevice(['devices' => $devices, 'expanded_devices' => $payload['expanded_devices'] ?? []]);
        if ($rootDisk === null) {
            throw new InvalidArgumentException('No root disk device (type=disk, path=/) found in instance payload.');
        }

        $rootName = $rootDisk['name'];
        $existingLocalRoot = Cast::arrayOrNull($devices[$rootName] ?? null);
        $rootDevice = $existingLocalRoot ?? $rootDisk['device'];
        $rootDevice['size'] = sprintf('%dGiB', $target->diskGb);
        $devices[$rootName] = $rootDevice;

        $localNic = $this->findLocalNicDevice(['devices' => $devices]);
        $requestedDownload = $target->network?->downloadMbps;
        $requestedUpload = $target->network?->uploadMbps;
        $hasNetworkLimits = $requestedDownload !== null || $requestedUpload !== null;

        if ($localNic === null) {
            if ($hasNetworkLimits) {
                throw new DomainException('NETWORK_LIMIT_NO_NIC: Cannot apply network bandwidth limits because the instance has no local NIC device.');
            }
        } else {
            $nicName = $localNic['name'];
            $nicDevice = $localNic['device'];

            if ($requestedDownload !== null) {
                $nicDevice[NicDeviceBuilder::LIMIT_KEYS['download']] = sprintf('%dMbit', $requestedDownload);
            } else {
                unset($nicDevice[NicDeviceBuilder::LIMIT_KEYS['download']]);
            }

            if ($requestedUpload !== null) {
                $nicDevice[NicDeviceBuilder::LIMIT_KEYS['upload']] = sprintf('%dMbit', $requestedUpload);
            } else {
                unset($nicDevice[NicDeviceBuilder::LIMIT_KEYS['upload']]);
            }

            $devices[$nicName] = $nicDevice;
        }

        $payload['devices'] = $devices;

        return $payload;
    }

    /**
     * Parse a human-readable size string (B, KB, MB, GB, TB, KiB, MiB, GiB, TiB) into bytes.
     */
    public function parseSizeToBytes(string $value): int
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw new InvalidArgumentException('Size value cannot be empty.');
        }

        if (preg_match('/^(\d+(?:\.\d+)?)\s*([a-zA-Z]*)$/', $trimmed, $matches) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid size specification "%s".', $value));
        }

        $number = (float) $matches[1];
        $unit = strtolower($matches[2]);

        if ($unit === '') {
            return (int) round($number);
        }

        if (! array_key_exists($unit, self::SIZE_UNIT_MULTIPLIERS)) {
            throw new InvalidArgumentException(sprintf('Unsupported size unit "%s" in "%s".', $matches[2], $value));
        }

        return (int) round($number * (float) self::SIZE_UNIT_MULTIPLIERS[$unit]);
    }

    /**
     * Find the root disk device (`type === 'disk'` and `path === '/'`) in local devices (falling back to expanded_devices).
     *
     * @param  array<string, mixed>  $instanceData
     * @return array{name: string, device: array<string, mixed>}|null
     */
    public function findRootDiskDevice(array $instanceData): ?array
    {
        $localDevices = Cast::arrayOrNull($instanceData['devices'] ?? null) ?? [];
        $found = $this->searchDiskDevice($localDevices);
        if ($found !== null) {
            return $found;
        }

        $expandedDevices = Cast::arrayOrNull($instanceData['expanded_devices'] ?? null) ?? [];

        return $this->searchDiskDevice($expandedDevices);
    }

    /**
     * Find the local NIC device (`type === 'nic'` in local `devices` only), preferring `eth0`.
     *
     * @param  array<string, mixed>  $instanceData
     * @return array{name: string, device: array<string, mixed>}|null
     */
    public function findLocalNicDevice(array $instanceData): ?array
    {
        $localDevices = Cast::arrayOrNull($instanceData['devices'] ?? null) ?? [];
        if ($localDevices === []) {
            return null;
        }

        $eth0 = Cast::arrayOrNull($localDevices['eth0'] ?? null);
        if ($eth0 !== null && Cast::string($eth0['type'] ?? '') === 'nic') {
            return ['name' => 'eth0', 'device' => $eth0];
        }

        foreach ($localDevices as $name => $rawDevice) {
            $device = Cast::arrayOrNull($rawDevice);
            if ($device === null) {
                continue;
            }

            if (Cast::string($device['type'] ?? '') === 'nic') {
                return ['name' => (string) $name, 'device' => $device];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $devices
     * @return array{name: string, device: array<string, mixed>}|null
     */
    private function searchDiskDevice(array $devices): ?array
    {
        $rootCandidate = Cast::arrayOrNull($devices['root'] ?? null);
        if ($rootCandidate !== null && $this->isRootDisk($rootCandidate)) {
            return ['name' => 'root', 'device' => $rootCandidate];
        }

        foreach ($devices as $name => $rawDevice) {
            $device = Cast::arrayOrNull($rawDevice);
            if ($device !== null && $this->isRootDisk($device)) {
                return ['name' => (string) $name, 'device' => $device];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $device
     */
    private function isRootDisk(array $device): bool
    {
        return Cast::string($device['type'] ?? '') === 'disk'
            && Cast::string($device['path'] ?? '') === '/';
    }

    private function parseCpuCount(mixed $raw): int
    {
        if (is_int($raw)) {
            return max(0, $raw);
        }

        if (is_string($raw)) {
            $trimmed = trim($raw);
            if ($trimmed === '') {
                return 0;
            }
            if (is_numeric($trimmed)) {
                return max(0, (int) $trimmed);
            }
        }

        return 0;
    }

    private function parseMemoryMb(mixed $raw): int
    {
        if (is_int($raw)) {
            return intdiv(max(0, $raw), self::BYTES_PER_MIB);
        }

        if (is_string($raw) && trim($raw) !== '') {
            return intdiv(max(0, $this->parseSizeToBytes($raw)), self::BYTES_PER_MIB);
        }

        return 0;
    }

    private function parseDiskGb(mixed $raw): int
    {
        if (is_int($raw)) {
            return intdiv(max(0, $raw), self::BYTES_PER_GIB);
        }

        if (is_string($raw) && trim($raw) !== '') {
            return intdiv(max(0, $this->parseSizeToBytes($raw)), self::BYTES_PER_GIB);
        }

        return 0;
    }

    private function parseBandwidthMbps(mixed $raw): ?int
    {
        if (is_int($raw)) {
            return $raw > 0 ? $raw : null;
        }

        if (! is_string($raw)) {
            return null;
        }

        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^(\d+(?:\.\d+)?)\s*([a-zA-Z]*)$/', $trimmed, $matches) !== 1) {
            return null;
        }

        $value = (float) $matches[1];
        $unit = strtolower($matches[2]);

        return match ($unit) {
            '', 'mbit' => (int) round($value),
            'kbit' => (int) round($value / 1000.0),
            'gbit' => (int) round($value * 1000.0),
            'tbit' => (int) round($value * 1000000.0),
            'bit' => (int) round($value / 1000000.0),
            default => null,
        };
    }
}
