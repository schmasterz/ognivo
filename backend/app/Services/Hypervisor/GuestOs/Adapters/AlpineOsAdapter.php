<?php

declare(strict_types=1);

namespace App\Services\Hypervisor\GuestOs\Adapters;

use App\Services\Hypervisor\GuestOs\Contracts\GuestOsAdapterInterface;
use Override;

/**
 * Guest OS adapter for Alpine Linux using Cloud-Init Network Config version 1 (ENI).
 *
 * Alpine's ENI renderer with ifupdown-ng crashes on Netplan v2 with KeyError: gateway or unhandled onlink,
 * whereas version 1 static configuration with gateway 169.254.0.1 generates native kernel on-link routing cleanly.
 */
final class AlpineOsAdapter implements GuestOsAdapterInterface
{
    #[Override]
    public function family(): string
    {
        return 'alpine';
    }

    #[Override]
    public function defaultUsername(): string
    {
        return 'alpine';
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
        return $this->buildVersion1NetworkConfig(
            macAddress: $macAddress,
            cidr: "{$ipAddress}/32",
            gateway: '169.254.0.1',
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
        return $this->buildVersion1NetworkConfig(
            macAddress: $macAddress,
            cidr: "{$ipAddress}/{$prefix}",
            gateway: $gateway,
            dnsServers: $dnsServers,
            instanceType: $instanceType,
        );
    }

    /**
     * Build a Cloud-Init Network Config version 1 YAML document for eth0.
     *
     * @param  array<string>|null  $dnsServers
     */
    private function buildVersion1NetworkConfig(
        string $macAddress,
        string $cidr,
        ?string $gateway,
        ?array $dnsServers,
        string $instanceType,
    ): string {
        $macBlock = $instanceType === 'virtual-machine'
            ? "\n    mac_address: '{$macAddress}'"
            : '';

        $gatewayLine = '';
        if ($gateway !== null && trim($gateway) !== '') {
            $gw = trim($gateway);
            $gatewayLine = "\n        gateway: '{$gw}'";
        }

        $dnsList = $dnsServers !== null && $dnsServers !== [] ? $dnsServers : ['1.1.1.1', '8.8.8.8'];
        $dnsLines = implode("\n", array_map(fn (string $dns): string => sprintf('          - %s', trim($dns)), $dnsList));

        return <<<YAML
version: 1
config:
  - type: physical
    name: eth0{$macBlock}
    subnets:
      - type: static
        address: '{$cidr}'{$gatewayLine}
        dns_nameservers:
{$dnsLines}
YAML;
    }

    /**
     * Build a Cloud-Init vendor-data YAML document to manage /etc/resolv.conf via cloud-init's cc_resolv_conf module.
     *
     * @param  array<string>|null  $dnsServers
     */
    public function buildVendorData(?array $dnsServers = null): string
    {
        $dnsList = $dnsServers !== null && $dnsServers !== [] ? $dnsServers : ['1.1.1.1', '8.8.8.8'];
        $dnsLines = implode("\n", array_map(fn (string $dns): string => sprintf('    - %s', trim($dns)), $dnsList));

        return <<<YAML
#cloud-config
manage_resolv_conf: true
resolv_conf:
  nameservers:
{$dnsLines}
YAML;
    }
}
