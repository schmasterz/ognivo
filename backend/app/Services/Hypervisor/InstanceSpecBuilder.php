<?php

declare(strict_types=1);

namespace App\Services\Hypervisor;

use App\Models\Image;
use App\Models\IpAllocation;
use App\Models\VirtualMachine;
use RuntimeException;

/**
 * Service constructing Incus instance payload specifications according to platform architecture standards,
 * utilizing routed NIC devices and Netplan v2 link-local default routing for virtual machines.
 */
class InstanceSpecBuilder
{
    /**
     * Build the instance creation payload for Incus/LXD REST API.
     *
     * @return array<string, mixed>
     */
    public function build(
        VirtualMachine $vm,
        IpAllocation|string|null $allocation = null,
        string $storagePool = 'default',
        ?string $network = null,
        mixed $allocatedIp = null,
        mixed $allocatedIpv6 = null,
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

        $source = [
            'type' => 'image',
            'mode' => 'pull',
            'server' => $image->source,
            'protocol' => $image->protocol,
            'alias' => $image->name,
        ];

        $config = [
            'limits.cpu' => (string) $vm->cpu_cores,
            'limits.memory' => sprintf('%dMB', $vm->ram_mb),
        ];

        $userData = $this->extractUserData($vm);
        if ($userData !== null && $userData !== '') {
            $config['cloud-init.user-data'] = $userData;
        }

        $allocation = $this->resolveAllocation($vm, $allocation);
        $isManual = ($allocation?->pool?->network_mode === 'manual');

        $devices = [
            'root' => [
                'type' => 'disk',
                'pool' => $storagePool,
                'path' => '/',
                'size' => sprintf('%dGB', $vm->disk_gb),
            ],
        ];

        if (! $isManual) {
            $parentInterface = $allocation?->pool?->parent_interface ?? 'eth0';
            $ipAddress = $allocation?->ip_string ?? $vm->ipv4_address;
            $macAddress = $allocation?->mac_address;

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
                $dnsServers = $allocation->pool?->dns_servers ?? ['1.1.1.1', '8.8.8.8'];
                $effectiveMac = $macAddress ?: '02:54:00:00:00:01';

                $config['cloud-init.network-config'] = $this->generateNetplanConfig(
                    $effectiveMac,
                    $allocation->ip_string,
                    $dnsServers
                );
            }
        }

        return [
            'name' => $vm->name,
            'type' => $vm->instance_type === 'container' ? 'container' : 'virtual-machine',
            'source' => $source,
            'config' => $config,
            'devices' => $devices,
        ];
    }

    /**
     * Generate Netplan v2 YAML configuration with link-local default route.
     *
     * @param  array<string>|null  $dnsServers
     */
    public function generateNetplanConfig(string $macAddress, string $ipAddress, ?array $dnsServers = null): string
    {
        $dnsList = ! empty($dnsServers) ? $dnsServers : ['1.1.1.1', '8.8.8.8'];
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
