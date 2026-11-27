<?php

declare(strict_types=1);

namespace App\Contracts\Data;

/**
 * Actor metadata identifying the user or administrator who initiated an authorization request (Developer Preview).
 *
 * @experimental This extension contract is in Developer Preview and may evolve in minor 1.x releases based on feedback from real reference implementations. It will be frozen under full SemVer once proven with core-integrated modules.
 */
final readonly class VpsAuthorizationInitiator
{
    public function __construct(
        public int $id,
        public bool $isAdmin,
    ) {}
}
