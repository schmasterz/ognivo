<?php

declare(strict_types=1);

namespace App\Services\Hypervisor\GuestOs\Adapters;

use App\Services\Network\NetworkConstants;
use Override;
use Symfony\Component\Yaml\Yaml;

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

    /**
     * @return array<int, array<string, mixed>>
     */
    #[Override]
    protected function routedRoutes(): array
    {
        return [
            ['to' => '0.0.0.0/0', 'via' => NetworkConstants::ROUTED_GUEST_GATEWAY],
        ];
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
    #[Override]
    public function buildVendorData(?array $dnsServers = null, string $instanceType = 'virtual-machine'): ?string
    {
        if ($instanceType !== 'container') {
            return null;
        }

        $dnsList = NetworkConstants::normalizeDnsServers($dnsServers);
        $content = implode("\n", array_map(fn (string $dns): string => sprintf('nameserver %s', $dns), $dnsList))."\n";

        $data = [
            'write_files' => [
                [
                    'path' => '/etc/resolv.conf',
                    'permissions' => '0644',
                    'content' => $content,
                ],
            ],
            'runcmd' => [
                "nmcli connection up 'cloud-init eth0' || true",
            ],
        ];

        return "#cloud-config\n".Yaml::dump($data, inline: 10, indent: 2, flags: Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }
}
