<?php

declare(strict_types=1);

namespace App\Services\Hypervisor\GuestOs\Adapters;

use App\Services\Hypervisor\GuestOs\Contracts\GuestOsAdapterInterface;
use App\Support\Network\NetworkConstants;
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
                'cloud-init' => [
                    'type' => 'disk',
                    'source' => 'cloud-init:config',
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
                ['to' => NetworkConstants::ROUTED_GUEST_GATEWAY.'/32'],
                ['to' => '0.0.0.0/0', 'via' => NetworkConstants::ROUTED_GUEST_GATEWAY],
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
        $gateway4 = null;
        if ($gateway !== null && trim($gateway) !== '') {
            $gateway4 = trim($gateway);
            $routes[] = [
                'to' => '0.0.0.0/0',
                'via' => $gateway4,
            ];
        }

        return $this->buildNetplanConfig(
            cidr: "{$ipAddress}/{$prefix}",
            routes: $routes,
            dnsServers: $dnsServers,
            instanceType: $instanceType,
            gateway4: $gateway4,
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
     * Merge routed-mode /etc/rc.conf.d/routing configuration into BSD cloud-init user-data.
     *
     * FreeBSD's nuageinit requires a #cloud-config YAML mapping to process network_config and
     * ignores Netplan v2 routes entries, so /32 routed mode writes /etc/rc.conf.d/routing via write_files.
     */
    public function mergeRoutedUserData(?string $userData, string $instanceType = 'virtual-machine'): string
    {
        $interface = $this->primaryInterfaceName($instanceType);
        $gw = NetworkConstants::ROUTED_GUEST_GATEWAY;

        $routingFile = [
            'path' => '/etc/rc.conf.d/routing',
            'permissions' => '0644',
            'content' => sprintf(
                "static_routes=\"ognivo_gw\"\nroute_ognivo_gw=\"-host %s -iface %s\"\ndefaultrouter=\"%s\"\n",
                $gw,
                $interface,
                $gw,
            ),
        ];

        if ($userData !== null && trim($userData) !== '') {
            $trimmed = ltrim($userData);
            if (! str_starts_with($trimmed, '#cloud-config')) {
                return $userData;
            }

            $body = preg_replace('/^#cloud-config[^\r\n]*(?:\r?\n|$)/', '', $trimmed) ?? '';
            $parsed = trim($body) === '' ? [] : Yaml::parse($body);

            if (is_array($parsed)) {
                $existingFiles = isset($parsed['write_files']) && is_array($parsed['write_files'])
                    ? array_values($parsed['write_files'])
                    : [];
                $existingFiles[] = $routingFile;
                $parsed['write_files'] = $existingFiles;

                return "#cloud-config\n".Yaml::dump($parsed, inline: 10, indent: 2, flags: Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
            }

            return $userData;
        }

        return "#cloud-config\n".Yaml::dump(['write_files' => [$routingFile]], inline: 10, indent: 2, flags: Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
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
        ?string $gateway4 = null,
    ): string {
        $interface = $this->primaryInterfaceName($instanceType);
        $resolvedDns = NetworkConstants::normalizeDnsServers($dnsServers);

        $interfaceConfig = [
            'addresses' => [$cidr],
        ];

        if ($gateway4 !== null) {
            $interfaceConfig['gateway4'] = $gateway4;
        }

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
