<?php

declare(strict_types=1);

namespace App\Services\Hypervisor\GuestOs\Adapters;

use App\Services\Hypervisor\GuestOs\Contracts\GuestOsAdapterInterface;
use Override;
use Symfony\Component\Yaml\Yaml;

/**
 * Guest OS adapter for BSD cloud images (e.g. FreeBSD) using vtnet0 on virtual machines without interface renaming.
 */
final class BsdOsAdapter implements GuestOsAdapterInterface
{
    #[Override]
    public function family(): string
    {
        return 'bsd';
    }

    #[Override]
    public function defaultUsername(): string
    {
        return 'freebsd';
    }

    #[Override]
    public function primaryInterfaceName(string $instanceType): string
    {
        return $instanceType === 'virtual-machine' ? 'vtnet0' : 'eth0';
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    public function extraInstanceConfig(string $instanceType): array
    {
        if ($instanceType === 'virtual-machine') {
            return ['security.secureboot' => 'false'];
        }

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
    ): string {
        return $this->buildNetplanConfig(
            cidr: "{$ipAddress}/32",
            routes: [
                ['to' => '169.254.0.1/32'],
                ['to' => '0.0.0.0/0', 'via' => '169.254.0.1'],
            ],
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
    ): string {
        $routes = [];
        if ($gateway !== null && trim($gateway) !== '') {
            $routes[] = [
                'to' => '0.0.0.0/0',
                'via' => trim($gateway),
            ];
        }

        return $this->buildNetplanConfig(
            cidr: "{$ipAddress}/{$prefix}",
            routes: $routes,
            dnsServers: $dnsServers,
            instanceType: $instanceType,
        );
    }

    /**
     * Build and serialize a Netplan v2 YAML document targeting the BSD primary interface directly without match/set-name renaming.
     *
     * @param  array<int, array<string, mixed>>  $routes
     * @param  array<string>|null  $dnsServers
     */
    private function buildNetplanConfig(
        string $cidr,
        array $routes,
        ?array $dnsServers,
        string $instanceType,
    ): string {
        $interface = $this->primaryInterfaceName($instanceType);
        $dnsList = $dnsServers !== null && $dnsServers !== [] ? $dnsServers : ['1.1.1.1', '8.8.8.8'];
        $resolvedDns = array_values(array_map(static fn (string $dns): string => trim($dns), $dnsList));

        $interfaceConfig = [
            'addresses' => [$cidr],
        ];

        if ($routes !== []) {
            $interfaceConfig['routes'] = $routes;
        }

        $interfaceConfig['nameservers'] = [
            'addresses' => $resolvedDns,
        ];

        $config = [
            'network' => [
                'version' => 2,
                'ethernets' => [
                    $interface => $interfaceConfig,
                ],
            ],
        ];

        return Yaml::dump($config, inline: 10, indent: 2);
    }
}
