<?php

declare(strict_types=1);

namespace App\Services\Hypervisor\GuestOs\Adapters;

use Override;

/**
 * Guest OS adapter for Ubuntu cloud images using Netplan on-link link-local routing.
 */
class UbuntuOsAdapter extends DebianOsAdapter
{
    #[Override]
    public function defaultUsername(): string
    {
        return 'ubuntu';
    }

    #[Override]
    protected function routedRoutesBlock(): string
    {
        return <<<'YAML'
      routes:
        - to: 0.0.0.0/0
          via: 169.254.0.1
          on-link: true
YAML;
    }
}
