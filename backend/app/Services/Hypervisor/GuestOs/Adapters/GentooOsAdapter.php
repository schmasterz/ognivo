<?php

declare(strict_types=1);

namespace App\Services\Hypervisor\GuestOs\Adapters;

use Override;

/**
 * Guest OS adapter for Gentoo Linux cloud images requiring an explicit /32 host route to 169.254.0.1.
 */
final class GentooOsAdapter extends AbstractLinuxCloudInitOsAdapter
{
    #[Override]
    public function family(): string
    {
        return 'gentoo';
    }

    #[Override]
    public function defaultUsername(): string
    {
        return 'gentoo';
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
}
