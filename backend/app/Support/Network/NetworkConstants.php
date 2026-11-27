<?php

declare(strict_types=1);

namespace App\Support\Network;

/**
 * Shared networking constants for IPAM and guest network configuration.
 */
final class NetworkConstants
{
    public const string ROUTED_GUEST_GATEWAY = '169.254.0.1';

    public const string LINK_LOCAL_CIDR = '169.254.0.0/16';

    /**
     * Default public DNS resolvers used when no pool-specific DNS servers are configured.
     *
     * @var list<string>
     */
    public const array DEFAULT_DNS_SERVERS = ['1.1.1.1', '8.8.8.8'];

    /**
     * Normalize DNS server entries, falling back to DEFAULT_DNS_SERVERS when empty.
     *
     * @param  array<string>|null  $dnsServers
     * @return list<string>
     */
    public static function normalizeDnsServers(?array $dnsServers): array
    {
        $dnsList = $dnsServers !== null && $dnsServers !== [] ? $dnsServers : self::DEFAULT_DNS_SERVERS;

        return array_values(array_map(static fn (string $dns): string => trim($dns), $dnsList));
    }
}
