<?php

declare(strict_types=1);

namespace App\Contracts\Data;

use App\Enums\Vps\VpsAuthorizationStatus;

/**
 * Authorization decision returned for a VPS plan-change request (Developer Preview).
 *
 * @experimental This extension contract is in Developer Preview and may evolve in minor 1.x releases based on feedback from real reference implementations. It will be frozen under full SemVer once proven with core-integrated modules.
 */
final readonly class VpsPlanChangeAuthorizationResult
{
    public function __construct(
        public VpsAuthorizationStatus $status,
        public ?string $reference = null,
        public ?string $reasonCode = null,
        public ?string $safeMessage = null,
    ) {}
}
