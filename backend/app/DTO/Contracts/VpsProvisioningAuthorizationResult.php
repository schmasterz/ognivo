<?php

declare(strict_types=1);

namespace App\DTO\Contracts;

/**
 * Authorization decision returned for a VPS provisioning request (Developer Preview).
 *
 * @experimental This extension contract is in Developer Preview and may evolve in minor 1.x releases based on feedback from real reference implementations. It will be frozen under full SemVer once proven with core-integrated modules.
 */
final readonly class VpsProvisioningAuthorizationResult
{
    public function __construct(
        public VpsAuthorizationStatus $status,
        public ?string $reference = null,
        public ?string $reasonCode = null,
        public ?string $safeMessage = null,
    ) {}
}
