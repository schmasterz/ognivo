<?php

declare(strict_types=1);

namespace App\Services\Hypervisor\GuestOs\Adapters;

use Override;

/**
 * Guest OS adapter for Debian-based cloud images requiring an explicit /32 host route to 169.254.0.1.
 */
class DebianOsAdapter extends AbstractLinuxCloudInitOsAdapter
{
    #[Override]
    public function family(): string
    {
        return 'debian';
    }

    #[Override]
    public function defaultUsername(): string
    {
        return 'debian';
    }
}
