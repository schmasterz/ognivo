<?php

declare(strict_types=1);

namespace App\DTO\Contracts;

/**
 * Decision status returned by a VPS provisioning or plan-change authorizer (Developer Preview).
 *
 * @experimental This extension contract is in Developer Preview and may evolve in minor 1.x releases based on feedback from real reference implementations. It will be frozen under full SemVer once proven with core-integrated modules.
 */
enum VpsAuthorizationStatus: string
{
    case Allowed = 'allowed';
    case Denied = 'denied';
    case Pending = 'pending';
}
