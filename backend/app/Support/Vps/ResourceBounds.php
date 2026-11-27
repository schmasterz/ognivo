<?php

declare(strict_types=1);

namespace App\Support\Vps;

/**
 * Single source of truth for VPS resource bounds and supported instance types.
 */
final class ResourceBounds
{
    public const int CPU_MIN = 1;

    public const int CPU_MAX = 64;

    public const int RAM_MB_MIN = 512;

    public const int RAM_MB_MAX = 131072;

    public const int DISK_GB_MIN = 5;

    public const int DISK_GB_MAX = 2000;

    public const int NET_MBPS_MIN = 1;

    public const int NET_MBPS_MAX = 100000;

    /**
     * Supported Incus/LXD instance types.
     *
     * @var list<string>
     */
    public const array INSTANCE_TYPES = ['virtual-machine', 'container'];
}
