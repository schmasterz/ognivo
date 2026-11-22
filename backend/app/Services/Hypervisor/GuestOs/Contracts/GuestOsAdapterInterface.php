<?php

declare(strict_types=1);

namespace App\Services\Hypervisor\GuestOs\Contracts;

/**
 * Contract for guest OS-specific hypervisor configuration and cloud-init networking adapters.
 */
interface GuestOsAdapterInterface
{
    /**
     * Get the normalized guest OS family identifier (e.g. 'ubuntu', 'debian', 'rhel').
     */
    public function family(): string;

    /**
     * Get the default cloud-init login username for the guest OS image.
     */
    public function defaultUsername(): string;

    /**
     * Get the primary network interface name inside the guest OS for the given instance type.
     */
    public function primaryInterfaceName(string $instanceType): string;

    /**
     * Get extra hypervisor instance config keys required by the guest OS.
     *
     * @return array<string, string>
     */
    public function extraInstanceConfig(string $instanceType): array;

    /**
     * Get extra hypervisor devices required by the guest OS.
     *
     * @return array<string, array<string, string>>
     */
    public function extraDevices(string $instanceType): array;

    /**
     * Build the cloud-init network configuration for routed networking mode.
     *
     * @param  array<string>|null  $dnsServers
     */
    public function buildRoutedNetworkConfig(
        string $macAddress,
        string $ipAddress,
        ?array $dnsServers = null,
        string $instanceType = 'virtual-machine',
    ): ?string;

    /**
     * Build the cloud-init network configuration for subnet-based networking modes (bridged / macvlan).
     *
     * @param  array<string>|null  $dnsServers
     */
    public function buildSubnetNetworkConfig(
        string $macAddress,
        string $ipAddress,
        int $prefix = 24,
        ?string $gateway = null,
        ?array $dnsServers = null,
        string $instanceType = 'virtual-machine',
    ): ?string;

    /**
     * Build optional cloud-init vendor-data configuration for the guest OS.
     *
     * @param  array<string>|null  $dnsServers
     */
    public function buildVendorData(
        ?array $dnsServers = null,
        string $instanceType = 'virtual-machine',
    ): ?string;
}
