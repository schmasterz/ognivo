<?php

declare(strict_types=1);

namespace App\Services\Hypervisor\GuestOs;

use App\Models\Image;
use App\Services\Hypervisor\GuestOs\Adapters\DebianOsAdapter;
use App\Services\Hypervisor\GuestOs\Adapters\RhelOsAdapter;
use App\Services\Hypervisor\GuestOs\Adapters\UbuntuOsAdapter;
use App\Services\Hypervisor\GuestOs\Contracts\GuestOsAdapterInterface;

/**
 * Resolves the appropriate guest OS adapter from an approved Image model or image name string.
 */
class GuestOsResolver
{
    /**
     * Map of RHEL-family image keywords to their default cloud-init usernames.
     *
     * @var array<string, string>
     */
    private const array RHEL_FAMILY_USERNAMES = [
        'almalinux' => 'almalinux',
        'rocky' => 'rocky',
        'centos' => 'centos',
        'fedora' => 'fedora',
        'oracle' => 'cloud-user',
        'rhel' => 'cloud-user',
    ];

    /**
     * Resolve a guest OS adapter for the given image model or name.
     */
    public function resolve(Image|string|null $image): GuestOsAdapterInterface
    {
        $normalized = strtolower(trim($image instanceof Image ? $image->name : (string) $image));

        foreach (self::RHEL_FAMILY_USERNAMES as $keyword => $username) {
            if (str_contains($normalized, $keyword)) {
                return new RhelOsAdapter($username);
            }
        }

        if (str_contains($normalized, 'debian') || str_contains($normalized, 'arch')) {
            return new DebianOsAdapter;
        }

        return new UbuntuOsAdapter;
    }
}
