<?php

declare(strict_types=1);

namespace App\Services\Hypervisor\GuestOs\Adapters;

use Override;

/**
 * Guest OS adapter for Arch Linux cloud images using systemd-networkd via Netplan v2.
 */
final class ArchOsAdapter extends AbstractLinuxCloudInitOsAdapter
{
    #[Override]
    public function family(): string
    {
        return 'arch';
    }

    #[Override]
    public function defaultUsername(): string
    {
        return 'arch';
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
     * @return array<int, array<string, mixed>>
     */
    #[Override]
    protected function routedRoutes(): array
    {
        return [
            ['to' => '169.254.0.1/32'],
            ['to' => '0.0.0.0/0', 'via' => '169.254.0.1'],
        ];
    }
}
