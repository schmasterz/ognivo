<?php

declare(strict_types=1);

namespace App\DTO\Hypervisor;

/**
 * Capability verdict for updating an individual instance resource dimension.
 */
enum ResourceUpdateVerdict: string
{
    case Unchanged = 'unchanged';
    case Online = 'online';
    case RequiresStop = 'requires_stop';
    case Unsupported = 'unsupported';
}
