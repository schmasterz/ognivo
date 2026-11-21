<?php

declare(strict_types=1);

namespace App\Services\Hypervisor\GuestOs\Adapters;

use Override;

/**
 * Guest OS adapter for RHEL-family cloud images (AlmaLinux, Rocky, CentOS, Fedora, Oracle, RHEL, Amazon Linux, openEuler).
 */
final class RhelOsAdapter extends AbstractLinuxCloudInitOsAdapter
{
    public function __construct(
        private readonly string $distroUsername = 'almalinux',
        private readonly bool $disableSecureBoot = false,
    ) {}

    #[Override]
    public function family(): string
    {
        return 'rhel';
    }

    #[Override]
    public function defaultUsername(): string
    {
        return $this->distroUsername;
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    public function extraInstanceConfig(string $instanceType): array
    {
        if ($this->disableSecureBoot && $instanceType === 'virtual-machine') {
            return ['security.secureboot' => 'false'];
        }

        return [];
    }

    #[Override]
    protected function routedRoutesBlock(): string
    {
        return <<<'YAML'
      routes:
        - to: 0.0.0.0/0
          via: 169.254.0.1
YAML;
    }

    /**
     * Build a Cloud-Init vendor-data YAML document to manage /etc/resolv.conf in containers.
     *
     * In LXC containers with routed networking, Incus configures eth0 at the kernel level before
     * NetworkManager starts. NetworkManager adopts the interface as 'connected (externally)' and
     * leaves /etc/resolv.conf empty unless cloud-init explicitly writes it.
     * RedHat/Rocky cloud images omit the resolv_conf module from cloud_init_modules in /etc/cloud/cloud.cfg,
     * so we use write_files to immediately populate /etc/resolv.conf in the init stage, and runcmd to
     * activate the cloud-init connection in NetworkManager once running.
     *
     * @param  array<string>|null  $dnsServers
     */
    public function buildVendorData(?array $dnsServers = null, string $instanceType = 'virtual-machine'): ?string
    {
        if ($instanceType !== 'container') {
            return null;
        }

        $dnsList = $dnsServers !== null && $dnsServers !== [] ? $dnsServers : ['1.1.1.1', '8.8.8.8'];
        $dnsLines = implode("\n", array_map(fn (string $dns): string => sprintf('      nameserver %s', trim($dns)), $dnsList));

        return <<<YAML
#cloud-config
write_files:
  - path: /etc/resolv.conf
    permissions: '0644'
    content: |
{$dnsLines}
runcmd:
  - nmcli connection up 'cloud-init eth0' || true
YAML;
    }
}
