<?php

declare(strict_types=1);

namespace App\Services\Hypervisor\GuestOs\Adapters;

use App\Services\Hypervisor\GuestOs\Contracts\GuestOsAdapterInterface;
use App\Services\Network\NetworkConstants;
use Override;
use Symfony\Component\Yaml\Yaml;

/**
 * Base adapter for Linux guest operating systems configured via cloud-init and Netplan v2.
 */
abstract class AbstractLinuxCloudInitOsAdapter implements GuestOsAdapterInterface
{
    /**
     * Build the Netplan v2 routes list for routed networking mode.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function routedRoutes(): array
    {
        return [
            ['to' => NetworkConstants::ROUTED_GUEST_GATEWAY.'/32'],
            ['to' => '0.0.0.0/0', 'via' => NetworkConstants::ROUTED_GUEST_GATEWAY],
        ];
    }

    #[Override]
    public function primaryInterfaceName(string $instanceType): string
    {
        return 'eth0';
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    public function extraInstanceConfig(string $instanceType): array
    {
        // Avoid setting migration.stateful, which conflicts with the agent:config disk device.
        return [];
    }

    /**
     * @return array<string, array<string, string>>
     */
    #[Override]
    public function extraDevices(string $instanceType): array
    {
        if ($instanceType === 'virtual-machine') {
            return [
                'agent' => [
                    'type' => 'disk',
                    'source' => 'agent:config',
                ],
            ];
        }

        return [];
    }

    /**
     * @param  array<string>|null  $dnsServers
     */
    #[Override]
    public function buildRoutedNetworkConfig(
        string $macAddress,
        string $ipAddress,
        ?array $dnsServers = null,
        string $instanceType = 'virtual-machine',
    ): ?string {
        return $this->buildNetplanConfig(
            macAddress: $macAddress,
            cidr: "{$ipAddress}/32",
            routes: $this->routedRoutes(),
            dnsServers: $dnsServers,
            instanceType: $instanceType,
        );
    }

    /**
     * @param  array<string>|null  $dnsServers
     */
    #[Override]
    public function buildSubnetNetworkConfig(
        string $macAddress,
        string $ipAddress,
        int $prefix = 24,
        ?string $gateway = null,
        ?array $dnsServers = null,
        string $instanceType = 'virtual-machine',
    ): ?string {
        $routes = [];
        if ($gateway !== null && trim($gateway) !== '') {
            $routes[] = [
                'to' => '0.0.0.0/0',
                'via' => trim($gateway),
            ];
        }

        return $this->buildNetplanConfig(
            macAddress: $macAddress,
            cidr: "{$ipAddress}/{$prefix}",
            routes: $routes,
            dnsServers: $dnsServers,
            instanceType: $instanceType,
        );
    }

    /**
     * @param  array<string>|null  $dnsServers
     */
    #[Override]
    public function buildVendorData(
        ?array $dnsServers = null,
        string $instanceType = 'virtual-machine',
    ): ?string {
        return null;
    }

    /**
     * Build and serialize a Netplan v2 YAML configuration for eth0.
     *
     * @param  array<int, array<string, mixed>>  $routes
     * @param  array<string>|null  $dnsServers
     */
    private function buildNetplanConfig(
        string $macAddress,
        string $cidr,
        array $routes,
        ?array $dnsServers,
        string $instanceType,
    ): string {
        $eth0 = [];

        if ($instanceType === 'virtual-machine') {
            $eth0['match'] = ['macaddress' => $macAddress];
            $eth0['set-name'] = 'eth0';
        }

        $eth0['addresses'] = [$cidr];

        if ($routes !== []) {
            $eth0['routes'] = $routes;
        }

        $eth0['nameservers'] = [
            'addresses' => $this->resolveDnsAddresses($dnsServers),
        ];

        $config = [
            'network' => [
                'version' => 2,
                'ethernets' => [
                    'eth0' => $eth0,
                ],
            ],
        ];

        return Yaml::dump($config, inline: 10, indent: 2);
    }

    /**
     * Resolve DNS nameserver entries, defaulting to NetworkConstants::DEFAULT_DNS_SERVERS when empty.
     *
     * @param  array<string>|null  $dnsServers
     * @return array<int, string>
     */
    protected function resolveDnsAddresses(?array $dnsServers): array
    {
        return NetworkConstants::normalizeDnsServers($dnsServers);
    }
}
