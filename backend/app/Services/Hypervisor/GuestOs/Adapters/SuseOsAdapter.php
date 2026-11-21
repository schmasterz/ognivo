<?php

declare(strict_types=1);

namespace App\Services\Hypervisor\GuestOs\Adapters;

use Override;

/**
 * Guest OS adapter for openSUSE / SUSE cloud images using sysconfig/NetworkManager via Netplan v2.
 */
final class SuseOsAdapter extends AbstractLinuxCloudInitOsAdapter
{
    #[Override]
    public function family(): string
    {
        return 'suse';
    }

    #[Override]
    public function defaultUsername(): string
    {
        return 'opensuse';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    #[Override]
    protected function routedRoutes(): array
    {
        return [
            ['to' => '0.0.0.0/0', 'via' => '169.254.0.1'],
        ];
    }
}
