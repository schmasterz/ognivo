<?php

declare(strict_types=1);

namespace App\Services\Hypervisor\GuestOs\Adapters;

use App\Services\Hypervisor\GuestOs\Contracts\GuestOsAdapterInterface;
use Override;

/**
 * Guest OS adapter for BSD cloud images (e.g. FreeBSD) using vtnet0 on virtual machines without interface renaming.
 */
class BsdOsAdapter implements GuestOsAdapterInterface
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
    ): ?string {
        $routesBlock = <<<'YAML'

      routes:
        - to: 169.254.0.1/32
        - to: 0.0.0.0/0
          via: 169.254.0.1
YAML;

        return $this->buildNetplanV2Config(
            cidr: "{$ipAddress}/32",
            routesBlock: $routesBlock,
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
        $routesBlock = '';
        if ($gateway !== null && trim($gateway) !== '') {
            $gw = trim($gateway);
            $routesBlock = <<<YAML

      routes:
        - to: 0.0.0.0/0
          via: {$gw}
YAML;
        }

        return $this->buildNetplanV2Config(
            cidr: "{$ipAddress}/{$prefix}",
            routesBlock: $routesBlock,
            dnsServers: $dnsServers,
            instanceType: $instanceType,
        );
    }

    /**
     * Build a Netplan v2 YAML document targeting the BSD primary interface directly without match/set-name renaming.
     *
     * @param  array<string>|null  $dnsServers
     */
    private function buildNetplanV2Config(
        string $cidr,
        string $routesBlock,
        ?array $dnsServers,
        string $instanceType,
    ): string {
        $interface = $this->primaryInterfaceName($instanceType);
        $dnsList = $dnsServers !== null && $dnsServers !== [] ? $dnsServers : ['1.1.1.1', '8.8.8.8'];
        $dnsLines = implode("\n", array_map(fn (string $dns): string => sprintf('          - %s', trim($dns)), $dnsList));

        return <<<YAML
network:
  version: 2
  ethernets:
    {$interface}:
      addresses:
        - {$cidr}{$routesBlock}
      nameservers:
        addresses:
{$dnsLines}
YAML;
    }
}
