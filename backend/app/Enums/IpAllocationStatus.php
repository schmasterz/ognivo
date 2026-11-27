<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle status of an IP address allocation.
 */
enum IpAllocationStatus: string
{
    case Allocated = 'allocated';
    case Cooling = 'cooling';
}
