<?php

declare(strict_types=1);

namespace App\Services\Hypervisor;

use App\Enums\IpAllocationStatus;
use App\Models\Image;
use App\Models\IpAllocation;
use App\Models\VirtualMachine;
use App\Services\Hypervisor\GuestOs\Adapters\BsdOsAdapter;
use App\Services\Hypervisor\GuestOs\GuestOsResolver;
use App\Services\Network\NetworkConstants;
use DomainException;
use RuntimeException;

/**
 * Service constructing Incus instance payload specifications according to platform architecture standards,
 * supporting routed, bridged, macvlan, and manual networking modes as well as universal image alias resolution.
 */
class InstanceSpecBuilder
{
    public function __construct(
        protected readonly GuestOsResolver $guestOsResolver = new GuestOsResolver,
        protected readonly NicDeviceBuilder $nicDeviceBuilder = new NicDeviceBuilder,
    ) {}

    /**
     * Build the instance creation payload for Incus/LXD REST API.
     *
     * @param  list<string>|null  $availableNetworks
     * @return array<string, mixed>
     */
    public function build(
        VirtualMachine $vm,
        ?IpAllocation $allocation = null,
        string $storagePool = 'default',
        ?array $availableNetworks = null,
    ): array {
        // Enforce admin-approved image allowlist
        $image = Image::where('name', $vm->os_image)->where('enabled', true)->first();
        if (! $image instanceof Image) {
            throw new RuntimeException(sprintf("OS image '%s' is not in the approved allowlist or is disabled.", $vm->os_image));
        }

        if ($vm->instance_type === 'container' && $image->os_type !== 'linux') {
            throw new RuntimeException('Containers (LXC) can only run Linux operating system images.');
        }

        $osAdapter = $this->guestOsResolver->resolve($image);

        $config = array_merge([
            'limits.cpu' => (string) $vm->cpu_cores,
            'limits.memory' => sprintf('%dMiB', $vm->ram_mb),
        ], $osAdapter->extraInstanceConfig($vm->instance_type));

        $userData = $this->extractUserData($vm);
        if ($userData !== null && $userData !== '') {
            $config['cloud-init.user-data'] = $userData;
        }

        $allocation = $this->resolveAllocation($vm, $allocation);
        $networkMode = $allocation !== null ? ($allocation->pool->network_mode ?: 'routed') : 'routed';
        $isManual = ($networkMode === 'manual');

        $devices = array_merge([
            'root' => [
                'type' => 'disk',
                'pool' => $storagePool,
                'path' => '/',
                'size' => sprintf('%dGiB', $vm->disk_gb),
            ],
        ], $osAdapter->extraDevices($vm->instance_type));

        if (! $isManual) {
            $configuredParent = $this->requireConfiguredParentInterface($allocation, $networkMode);
            $parentInterface = $this->resolveParentInterface($configuredParent, $networkMode, $availableNetworks);
            $ipAddress = $allocation?->ip_string ?? $vm->ipv4_address;
            $macAddress = $allocation?->mac_address;
            $dnsServers = $allocation?->pool?->dns_servers ?? NetworkConstants::DEFAULT_DNS_SERVERS;
            $effectiveMac = $macAddress ?: '02:54:00:00:00:01';
            $effectiveMode = ($networkMode === 'bridged' || $networkMode === 'macvlan') ? $networkMode : 'routed';

            $devices['eth0'] = $this->nicDeviceBuilder->build(
                networkMode: $effectiveMode,
                parentInterface: $parentInterface,
                ipAddress: $ipAddress,
                macAddress: $macAddress,
                downloadMbps: $vm->net_download_mbps,
                uploadMbps: $vm->net_upload_mbps,
            );

            if ($effectiveMode === 'bridged' || $effectiveMode === 'macvlan') {
                if ($allocation !== null && $ipAddress !== null && $ipAddress !== '') {
                    $prefix = $this->extractPrefixFromCidr($allocation->pool->cidr ?: $allocation->pool->subnet);
                    $gateway = $allocation->pool->gateway;

                    $networkConfig = $osAdapter->buildSubnetNetworkConfig(
                        $effectiveMac,
                        $ipAddress,
                        $prefix,
                        $gateway,
                        $dnsServers,
                        $vm->instance_type,
                    );

                    if ($networkConfig !== null && $networkConfig !== '') {
                        $config['cloud-init.network-config'] = $networkConfig;
                    }
                }

                if ($osAdapter->family() === 'bsd' && ! isset($config['cloud-init.user-data'])) {
                    $config['cloud-init.user-data'] = "#cloud-config\nmanage_etc_hosts: true\n";
                }
            } else {
                // Instances require cloud-init Netplan v2 configuration for DNS nameservers and link-local default route (NetworkConstants::ROUTED_GUEST_GATEWAY)
                if ($allocation !== null) {
                    $networkConfig = $osAdapter->buildRoutedNetworkConfig(
                        $effectiveMac,
                        $allocation->ip_string,
                        $dnsServers,
                        $vm->instance_type,
                    );

                    if ($networkConfig !== null && $networkConfig !== '') {
                        $config['cloud-init.network-config'] = $networkConfig;
                    }

                    if ($osAdapter instanceof BsdOsAdapter) {
                        $config['cloud-init.user-data'] = $osAdapter->mergeRoutedUserData($userData, $vm->instance_type);
                    }
                }
            }

            $vendorData = $osAdapter->buildVendorData($dnsServers, $vm->instance_type);
            if ($vendorData !== null && $vendorData !== '') {
                $config['cloud-init.vendor-data'] = $vendorData;
            }
        }

        $requiresCloudInit = isset($config['cloud-init.user-data']) || isset($config['cloud-init.network-config']) || isset($config['cloud-init.vendor-data']);

        $source = [
            'type' => 'image',
            'mode' => 'pull',
            'server' => $image->source,
            'protocol' => $image->protocol,
            'alias' => $this->resolveImageAlias($image, $requiresCloudInit),
        ];

        return [
            'name' => $vm->name,
            'type' => $vm->instance_type === 'container' ? 'container' : 'virtual-machine',
            'source' => $source,
            'config' => $config,
            'devices' => $devices,
        ];
    }

    /**
     * Normalize an image name/alias for hypervisor remote image servers.
     * Strips CLI remote prefixes (e.g. 'images:ubuntu/24.04' -> 'ubuntu/24.04')
     * and appends '/cloud' on LinuxContainers simplestreams when cloud-init configuration is present.
     */
    public function resolveImageAlias(Image $image, bool $requiresCloudInit = false): string
    {
        $alias = trim($image->name);

        // Strip leading CLI remote prefix (e.g. 'images:', 'local:') when followed by a slash-separated path
        if (preg_match('/^[a-z0-9_-]+:([a-z0-9_.-]+\/.+)$/i', $alias, $matches) === 1) {
            $alias = $matches[1];
        }

        if (
            $requiresCloudInit
            && in_array($image->os_type, ['linux', 'bsd'], true)
            && $image->protocol === 'simplestreams'
            && str_contains($image->source, 'images.linuxcontainers.org')
            && preg_match('/^[a-z0-9_.-]+(\/[a-z0-9_.-]+)?$/i', $alias) === 1
            && ! str_ends_with($alias, '/cloud')
            && ! str_ends_with($alias, '/default')
        ) {
            return $alias.'/cloud';
        }

        return $alias;
    }

    /**
     * Validate the configured parent network interface against host networks.
     *
     * When host networks were not queried ($availableNetworks is null or empty),
     * returns the configured value unchanged. When a non-empty list is provided
     * and the configured parent interface is absent, throws a DomainException.
     *
     * @param  list<string>|null  $availableNetworks
     */
    public function resolveParentInterface(string $configuredParent, string $networkMode, ?array $availableNetworks = null): string
    {
        if ($availableNetworks === null || $availableNetworks === []) {
            return $configuredParent;
        }

        if (in_array($configuredParent, $availableNetworks, true)) {
            return $configuredParent;
        }

        throw new DomainException(sprintf(
            'Permanent network configuration error: Configured parent_interface "%s" for network mode "%s" was not found on the hypervisor host (available: %s).',
            $configuredParent,
            $networkMode,
            implode(', ', $availableNetworks)
        ));
    }

    /**
     * Generate Netplan v2 YAML configuration with link-local default route (for routed mode).
     *
     * @param  array<string>|null  $dnsServers
     */
    public function generateNetplanConfig(
        string $macAddress,
        string $ipAddress,
        ?array $dnsServers = null,
        string $instanceType = 'virtual-machine',
        string $imageName = '',
    ): string {
        return (string) $this->guestOsResolver
            ->resolve($imageName)
            ->buildRoutedNetworkConfig($macAddress, $ipAddress, $dnsServers, $instanceType);
    }

    /**
     * Generate Netplan v2 YAML configuration for standard subnet modes (bridged / macvlan).
     *
     * @param  array<string>|null  $dnsServers
     */
    public function generateSubnetNetplanConfig(
        string $macAddress,
        string $ipAddress,
        int $prefix = 24,
        ?string $gateway = null,
        ?array $dnsServers = null,
        string $instanceType = 'virtual-machine',
        string $imageName = '',
    ): string {
        return (string) $this->guestOsResolver
            ->resolve($imageName)
            ->buildSubnetNetworkConfig($macAddress, $ipAddress, $prefix, $gateway, $dnsServers, $instanceType);
    }

    /**
     * Extract integer subnet prefix from CIDR string (defaults to 24).
     */
    protected function extractPrefixFromCidr(?string $cidr): int
    {
        if ($cidr !== null && str_contains($cidr, '/')) {
            $parts = explode('/', $cidr);
            if (isset($parts[1]) && is_numeric($parts[1])) {
                $prefix = (int) $parts[1];
                if ($prefix >= 8 && $prefix <= 32) {
                    return $prefix;
                }
            }
        }

        return 24;
    }

    /**
     * Extract user-data string from the VM's cloud_init configuration.
     */
    protected function extractUserData(VirtualMachine $vm): ?string
    {
        if (is_array($vm->cloud_init)) {
            $userData = $vm->cloud_init['user-data'] ?? $vm->cloud_init['user_data'] ?? null;

            return is_string($userData) ? $userData : null;
        }

        return null;
    }

    /**
     * Ensure a non-manual pool has a non-empty parent_interface configured.
     */
    protected function requireConfiguredParentInterface(?IpAllocation $allocation, string $networkMode): string
    {
        $configuredParent = $allocation !== null ? trim($allocation->pool->parent_interface) : '';
        if ($configuredParent === '') {
            $poolContext = $allocation !== null
                ? sprintf("IP pool '%s' (#%d)", $allocation->pool->name, $allocation->pool->id)
                : 'the target VM (no IP pool allocation)';

            throw new DomainException(sprintf(
                'Permanent network configuration error: %s uses network mode "%s" which requires a non-empty parent_interface.',
                $poolContext,
                $networkMode
            ));
        }

        return $configuredParent;
    }

    /**
     * Resolve the active allocated IP record.
     */
    protected function resolveAllocation(VirtualMachine $vm, ?IpAllocation $allocation): ?IpAllocation
    {
        if ($allocation !== null) {
            $allocation->loadMissing('pool');

            return $allocation;
        }

        if ($vm->relationLoaded('primaryAllocation') && $vm->primaryAllocation !== null) {
            $vm->primaryAllocation->loadMissing('pool');

            return $vm->primaryAllocation;
        }

        if ($vm->relationLoaded('allocations')) {
            /** @var IpAllocation|null $found */
            $found = $vm->allocations->first(fn (IpAllocation $a): bool => $a->status === IpAllocationStatus::Allocated);
            if ($found !== null) {
                $found->loadMissing('pool');

                return $found;
            }
        }

        if ($vm->ipv4_address !== null) {
            return IpAllocation::with('pool')->where('ip_string', $vm->ipv4_address)
                ->where('status', IpAllocationStatus::Allocated->value)
                ->first();
        }

        return null;
    }
}
