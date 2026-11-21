<?php

declare(strict_types=1);

namespace App\Services\Hypervisor\GuestOs;

use App\Models\Image;
use App\Services\Hypervisor\GuestOs\Adapters\AlpineOsAdapter;
use App\Services\Hypervisor\GuestOs\Adapters\ArchOsAdapter;
use App\Services\Hypervisor\GuestOs\Adapters\BsdOsAdapter;
use App\Services\Hypervisor\GuestOs\Adapters\DebianOsAdapter;
use App\Services\Hypervisor\GuestOs\Adapters\GentooOsAdapter;
use App\Services\Hypervisor\GuestOs\Adapters\RhelOsAdapter;
use App\Services\Hypervisor\GuestOs\Adapters\SuseOsAdapter;
use App\Services\Hypervisor\GuestOs\Adapters\UbuntuOsAdapter;
use App\Services\Hypervisor\GuestOs\Contracts\GuestOsAdapterInterface;

/**
 * Resolves the appropriate guest OS adapter from an approved Image model or image name string.
 */
final class GuestOsResolver
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

        if ($this->containsAny($normalized, ['alpine'])) {
            return new AlpineOsAdapter;
        }

        if (
            $this->containsAny($normalized, ['archlinux', 'cachyos'])
            || preg_match('/(^|[^a-z0-9])arch([^a-z0-9]|$)/', $normalized) === 1
        ) {
            return new ArchOsAdapter;
        }

        if ($this->containsAny($normalized, ['opensuse', 'sles', 'suse'])) {
            return new SuseOsAdapter;
        }

        if ($this->containsAny($normalized, ['gentoo'])) {
            return new GentooOsAdapter;
        }

        if ($this->containsAny($normalized, ['freebsd', 'openbsd', 'netbsd'])) {
            return new BsdOsAdapter;
        }

        if ($this->containsAny($normalized, ['openeuler'])) {
            return new RhelOsAdapter('root', disableSecureBoot: true);
        }

        if ($this->containsAny($normalized, ['amazonlinux', 'amazon'])) {
            return new RhelOsAdapter('ec2-user');
        }

        foreach (self::RHEL_FAMILY_USERNAMES as $keyword => $username) {
            if (str_contains($normalized, $keyword)) {
                return new RhelOsAdapter($username);
            }
        }

        if ($this->containsAny($normalized, ['debian', 'kali', 'devuan'])) {
            return new DebianOsAdapter;
        }

        return new UbuntuOsAdapter;
    }

    /**
     * Check whether the normalized image string contains any of the given keywords.
     *
     * @param  list<string>  $keywords
     */
    private function containsAny(string $haystack, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($haystack, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
