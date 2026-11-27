<?php

declare(strict_types=1);

namespace App\Contracts\Data;

/**
 * Request payload passed to a VPS plan-change authorizer extension (Developer Preview).
 *
 * @experimental This extension contract is in Developer Preview and may evolve in minor 1.x releases based on feedback from real reference implementations. It will be frozen under full SemVer once proven with core-integrated modules.
 */
final readonly class VpsPlanChangeAuthorizationRequest
{
    /**
     * @param  array<string, mixed>  $oldResources
     * @param  array<string, mixed>  $newResources
     */
    public function __construct(
        public string $requestId,
        public int $userId,
        public int $virtualMachineId,
        public ?int $fromVersionId,
        public int $toVersionId,
        public array $oldResources,
        public array $newResources,
        public VpsAuthorizationInitiator $initiator,
    ) {}
}
