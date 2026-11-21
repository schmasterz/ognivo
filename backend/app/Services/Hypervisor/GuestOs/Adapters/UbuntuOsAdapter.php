<?php

declare(strict_types=1);

namespace App\Services\Hypervisor\GuestOs\Adapters;

use Override;

/**
 * Guest OS adapter for Ubuntu cloud images using Netplan on-link link-local routing.
 */
final class UbuntuOsAdapter extends DebianOsAdapter
{
    #[Override]
    public function defaultUsername(): string
    {
        return 'ubuntu';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    #[Override]
    protected function routedRoutes(): array
    {
        return [
            [
                'to' => '0.0.0.0/0',
                'via' => '169.254.0.1',
                'on-link' => true,
            ],
        ];
    }
}
