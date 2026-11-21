<?php

declare(strict_types=1);

namespace App\Services\Hypervisor\GuestOs\Adapters;

use Override;

/**
 * Guest OS adapter for RHEL-family cloud images (AlmaLinux, Rocky, CentOS, Fedora, Oracle, RHEL, Amazon Linux, openEuler).
 */
class RhelOsAdapter extends AbstractLinuxCloudInitOsAdapter
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
}
