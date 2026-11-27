<?php

declare(strict_types=1);

namespace App\DTO\Hypervisor;

/**
 * Outcome status of an applyResourceUpdate hypervisor driver call.
 */
enum ResourceUpdateStatus: string
{
    case Applied = 'applied';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Failed = 'failed';
    case Unknown = 'unknown';
}
