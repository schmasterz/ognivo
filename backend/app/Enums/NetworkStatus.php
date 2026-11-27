<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * IP allocation and hypervisor network observation status for a VirtualMachine.
 */
enum NetworkStatus: string
{
    case Assigned = 'assigned';
    case Waiting = 'waiting';
    case Observed = 'observed';
    case Unverified = 'unverified';
    case Mismatch = 'mismatch';
    case Unassigned = 'unassigned';
}
