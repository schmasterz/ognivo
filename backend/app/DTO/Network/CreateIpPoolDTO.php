<?php

declare(strict_types=1);

namespace App\DTO\Network;

use App\DTO\Cast;

/**
 * Immutable Data Transfer Object for creating an IP subnet pool under the ranged IPAM model.
 */
final readonly class CreateIpPoolDTO
{
    /**
     * @param  list<string>|null  $dnsServers
     * @param  list<string>|null  $excludedIps
     */
    public function __construct(
        public string $name,
        public string $cidr,
        public int $hostId,
        public int $ipVersion = 4,
        public ?string $firstUsable = null,
        public ?string $lastUsable = null,
        public string $networkMode = 'routed',
        public string $parentInterface = 'eth0',
        public ?string $gateway = null,
        public ?array $dnsServers = null,
        public ?array $excludedIps = null,
        public int $cooldownSeconds = 300,
        public bool $isPublic = true,
        public bool $active = true,
        public ?string $description = null,
        public ?string $subnet = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $rawDns = Cast::arrayOrNull($data['dns_servers'] ?? null);
        /** @var list<string>|null $dnsServers */
        $dnsServers = $rawDns !== null
            ? array_values(array_filter($rawDns, 'is_string'))
            : null;

        $rawExcluded = Cast::arrayOrNull($data['excluded_ips'] ?? null);
        /** @var list<string>|null $excludedIps */
        $excludedIps = $rawExcluded !== null
            ? array_values(array_filter($rawExcluded, 'is_string'))
            : null;

        $cidr = Cast::string($data['cidr'] ?? $data['subnet'] ?? '');
        $hostId = Cast::int($data['host_id'] ?? 1);

        return new self(
            name: Cast::string($data['name'] ?? ''),
            cidr: $cidr,
            hostId: $hostId,
            ipVersion: Cast::int($data['ip_version'] ?? 4, 4),
            firstUsable: Cast::stringOrNull($data['first_usable'] ?? null),
            lastUsable: Cast::stringOrNull($data['last_usable'] ?? null),
            networkMode: Cast::string($data['network_mode'] ?? 'routed', 'routed'),
            parentInterface: Cast::string($data['parent_interface'] ?? 'eth0', 'eth0'),
            gateway: Cast::stringOrNull($data['gateway'] ?? null),
            dnsServers: $dnsServers,
            excludedIps: $excludedIps,
            cooldownSeconds: Cast::int($data['cooldown_seconds'] ?? 300, 300),
            isPublic: Cast::bool($data['is_public'] ?? true, true),
            active: Cast::bool($data['active'] ?? true, true),
            description: Cast::stringOrNull($data['description'] ?? null),
            subnet: $cidr,
        );
    }
}
