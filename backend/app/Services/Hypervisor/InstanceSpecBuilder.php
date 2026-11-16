<?php

declare(strict_types=1);

namespace App\Services\Hypervisor;

use App\Models\Image;
use App\Models\IpAllocation;
use App\Models\VirtualMachine;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Service constructing Incus instance payload specifications according to platform architecture standards,
 * supporting routed, bridged, macvlan, and manual networking modes as well as universal image alias resolution.
 */
class InstanceSpecBuilder
{
    /**
     * Build the instance creation payload for Incus/LXD REST API.
     *
     * @param  list<string>|null  $availableNetworks
     * @return array<string, mixed>
     */
    public function build(
        VirtualMachine $vm,
        IpAllocation|string|null $allocation = null,
        string $storagePool = 'default',
        ?string $network = null,
        mixed $allocatedIp = null,
        mixed $allocatedIpv6 = null,
        ?array $availableNetworks = null,
    ): array {
        if (is_string($allocation)) {
            $storagePool = $allocation;
            $allocation = null;
        }

        if ($allocation === null && $allocatedIp instanceof IpAllocation) {
            $allocation = $allocatedIp;
        }

        // Enforce admin-approved image allowlist
        $image = Image::where('name', $vm->os_image)->where('enabled', true)->first();
        if ($image === null) {
            throw new RuntimeException(sprintf("OS image '%s' is not in the approved allowlist or is disabled.", $vm->os_image));
        }

        if ($vm->instance_type === 'container' && $image->os_type !== 'linux') {
            throw new RuntimeException('Containers (LXC) can only run Linux operating system images.');
        }

        $config = [
            'limits.cpu' => (string) $vm->cpu_cores,
            'limits.memory' => sprintf('%dMB', $vm->ram_mb),
            'migration.stateful' => 'true',
        ];

        $userData = $this->extractUserData($vm);
        if ($userData !== null && $userData !== '') {
            $config['cloud-init.user-data'] = $userData;
        }

        $allocation = $this->resolveAllocation($vm, $allocation);
        $networkMode = $allocation !== null ? ($allocation->pool->network_mode ?: 'routed') : 'routed';
        $isManual = ($networkMode === 'manual');

        $devices = [
            'root' => [
                'type' => 'disk',
                'pool' => $storagePool,
                'path' => '/',
                'size' => sprintf('%dGB', $vm->disk_gb),
            ],
        ];

        if (! $isManual) {
            $configuredParent = ($allocation?->pool?->parent_interface ?? 'eth0') ?: 'eth0';
            $parentInterface = $this->resolveParentInterface($configuredParent, $networkMode, $availableNetworks);
            $ipAddress = $allocation?->ip_string ?? $vm->ipv4_address;
            $macAddress = $allocation?->mac_address;
            $dnsServers = $allocation?->pool?->dns_servers ?? ['1.1.1.1', '8.8.8.8'];
            $effectiveMac = $macAddress ?: '02:54:00:00:00:01';

            if ($networkMode === 'bridged' || $networkMode === 'macvlan') {
                $eth0 = [
                    'type' => 'nic',
                    'nictype' => $networkMode,
                    'parent' => $parentInterface,
                ];

                if ($macAddress !== null && $macAddress !== '') {
                    $eth0['hwaddr'] = $macAddress;
                }

                $devices['eth0'] = $eth0;

                if ($allocation !== null && $ipAddress !== null && $ipAddress !== '') {
                    $prefix = $this->extractPrefixFromCidr($allocation->pool->cidr ?: $allocation->pool->subnet);
                    $gateway = $allocation->pool->gateway;

                    $config['cloud-init.network-config'] = $this->generateSubnetNetplanConfig(
                        $effectiveMac,
                        $ipAddress,
                        $prefix,
                        $gateway,
                        $dnsServers
                    );
                }
            } else {
                // Default: 'routed' mode
                $eth0 = [
                    'type' => 'nic',
                    'nictype' => 'routed',
                    'parent' => $parentInterface,
                ];

                if ($ipAddress !== null && $ipAddress !== '') {
                    $eth0['ipv4.address'] = $ipAddress;
                }

                if ($macAddress !== null && $macAddress !== '') {
                    $eth0['hwaddr'] = $macAddress;
                }

                $devices['eth0'] = $eth0;

                // Virtual Machines require cloud-init Netplan v2 configuration for link-local default route (169.254.0.1)
                if ($vm->instance_type === 'virtual-machine' && $allocation !== null) {
                    $config['cloud-init.network-config'] = $this->generateNetplanConfig(
                        $effectiveMac,
                        $allocation->ip_string,
                        $dnsServers
                    );
                }
            }
        }

        $requiresCloudInit = isset($config['cloud-init.user-data']) || isset($config['cloud-init.network-config']);

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
            && $image->os_type === 'linux'
            && $image->protocol === 'simplestreams'
            && str_contains($image->source, 'images.linuxcontainers.org')
            && preg_match('/^[a-z0-9_-]+\/[a-z0-9._-]+$/i', $alias) === 1
        ) {
            return $alias.'/cloud';
        }

        return $alias;
    }

    /**
     * Resolve the effective parent network interface on the target host.
     * If the pool's configured interface exists on the host (or host networks were not queried),
     * returns the configured interface. Otherwise, selects the best matching host interface.
     *
     * @param  list<string>|null  $availableNetworks
     */
    public function resolveParentInterface(string $configuredParent, string $networkMode, ?array $availableNetworks = null): string
    {
        if ($availableNetworks === null || empty($availableNetworks) || in_array($configuredParent, $availableNetworks, true)) {
            return $configuredParent;
        }

        $candidates = array_values(array_filter(
            $availableNetworks,
            static fn (string $name): bool => $name !== 'lo'
                && ! str_starts_with($name, 'docker')
                && ! str_starts_with($name, 'tailscale')
                && ! str_starts_with($name, 'veth')
        ));

        if (empty($candidates)) {
            return $configuredParent;
        }

        $selected = null;

        if ($networkMode === 'bridged') {
            foreach ($candidates as $cand) {
                if (str_starts_with($cand, 'incusbr') || str_starts_with($cand, 'lxdbr') || str_starts_with($cand, 'br') || str_starts_with($cand, 'virbr')) {
                    $selected = $cand;
                    break;
                }
            }
        } else {
            foreach ($candidates as $cand) {
                if (
                    str_starts_with($cand, 'eno')
                    || str_starts_with($cand, 'enp')
                    || str_starts_with($cand, 'ens')
                    || str_starts_with($cand, 'eth')
                    || str_starts_with($cand, 'bond')
                ) {
                    $selected = $cand;
                    break;
                }
            }
        }

        $selected ??= $candidates[0];

        Log::warning(sprintf(
            'Configured parent_interface "%s" not found on hypervisor (available: %s). Auto-resolved to "%s" for mode "%s".',
            $configuredParent,
            implode(', ', $availableNetworks),
            $selected,
            $networkMode
        ));

        return $selected;
    }

    /**
     * Generate Netplan v2 YAML configuration with link-local default route (for routed mode).
     *
     * @param  array<string>|null  $dnsServers
     */
    public function generateNetplanConfig(string $macAddress, string $ipAddress, ?array $dnsServers = null): string
    {
        $dnsList = $dnsServers !== null && $dnsServers !== [] ? $dnsServers : ['1.1.1.1', '8.8.8.8'];
        $dnsLines = implode("\n", array_map(fn (string $dns): string => sprintf('          - %s', trim($dns)), $dnsList));

        return <<<YAML
network:
  version: 2
  ethernets:
    eth0:
      match:
        macaddress: "{$macAddress}"
      set-name: eth0
      addresses:
        - {$ipAddress}/32
      routes:
        - to: default
          via: 169.254.0.1
          on-link: true
      nameservers:
        addresses:
{$dnsLines}
YAML;
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
    ): string {
        $dnsList = $dnsServers !== null && $dnsServers !== [] ? $dnsServers : ['1.1.1.1', '8.8.8.8'];
        $dnsLines = implode("\n", array_map(fn (string $dns): string => sprintf('          - %s', trim($dns)), $dnsList));

        $routesBlock = '';
        if ($gateway !== null && trim($gateway) !== '') {
            $gw = trim($gateway);
            $routesBlock = <<<YAML

      routes:
        - to: default
          via: {$gw}
YAML;
        }

        return <<<YAML
network:
  version: 2
  ethernets:
    eth0:
      match:
        macaddress: "{$macAddress}"
      set-name: eth0
      addresses:
        - {$ipAddress}/{$prefix}{$routesBlock}
      nameservers:
        addresses:
{$dnsLines}
YAML;
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
            $found = $vm->allocations->first(fn (IpAllocation $a): bool => $a->status === 'allocated');
            if ($found !== null) {
                $found->loadMissing('pool');

                return $found;
            }
        }

        if ($vm->ipv4_address !== null) {
            return IpAllocation::with('pool')->where('ip_string', $vm->ipv4_address)
                ->where('status', 'allocated')
                ->first();
        }

        return null;
    }
}
