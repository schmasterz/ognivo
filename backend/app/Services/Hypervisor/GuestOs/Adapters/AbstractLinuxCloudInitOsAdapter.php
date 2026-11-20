<?php

declare(strict_types=1);

namespace App\Services\Hypervisor\GuestOs\Adapters;

use App\Services\Hypervisor\GuestOs\Contracts\GuestOsAdapterInterface;
use Override;

/**
 * Base adapter for Linux guest operating systems configured via cloud-init and Netplan v2.
 */
abstract class AbstractLinuxCloudInitOsAdapter implements GuestOsAdapterInterface
{
    /**
     * Build the Netplan v2 routes block for routed networking mode.
     */
    abstract protected function routedRoutesBlock(): string;

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
        $dnsLines = $this->formatDnsLines($dnsServers);
        $matchBlock = $this->buildEth0MatchBlock($macAddress, $instanceType);
        $routesBlock = $this->routedRoutesBlock();

        return <<<YAML
network:
  version: 2
  ethernets:
    eth0:{$matchBlock}
      addresses:
        - {$ipAddress}/32
{$routesBlock}
      nameservers:
        addresses:
{$dnsLines}
YAML;
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
        $dnsLines = $this->formatDnsLines($dnsServers);
        $matchBlock = $this->buildEth0MatchBlock($macAddress, $instanceType);

        $routesBlock = '';
        if ($gateway !== null && trim($gateway) !== '') {
            $gw = trim($gateway);
            $routesBlock = <<<YAML

      routes:
        - to: 0.0.0.0/0
          via: {$gw}
YAML;
        }

        return <<<YAML
network:
  version: 2
  ethernets:
    eth0:{$matchBlock}
      addresses:
        - {$ipAddress}/{$prefix}{$routesBlock}
      nameservers:
        addresses:
{$dnsLines}
YAML;
    }

    /**
     * Build the MAC address match and set-name block for virtual machines; containers use Incus-managed eth0 directly.
     */
    protected function buildEth0MatchBlock(string $macAddress, string $instanceType): string
    {
        if ($instanceType !== 'virtual-machine') {
            return '';
        }

        return <<<YAML

      match:
        macaddress: "{$macAddress}"
      set-name: eth0
YAML;
    }

    /**
     * Format DNS nameserver entries as indented YAML list lines, defaulting to public resolvers when empty.
     *
     * @param  array<string>|null  $dnsServers
     */
    protected function formatDnsLines(?array $dnsServers): string
    {
        $dnsList = $dnsServers !== null && $dnsServers !== [] ? $dnsServers : ['1.1.1.1', '8.8.8.8'];

        return implode("\n", array_map(fn (string $dns): string => sprintf('          - %s', trim($dns)), $dnsList));
    }
}
