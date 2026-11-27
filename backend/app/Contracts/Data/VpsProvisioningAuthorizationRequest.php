<?php

declare(strict_types=1);

namespace App\Contracts\Data;

/**
 * Request payload passed to a VPS provisioning authorizer extension (Developer Preview).
 *
 * @experimental This extension contract is in Developer Preview and may evolve in minor 1.x releases based on feedback from real reference implementations. It will be frozen under full SemVer once proven with core-integrated modules.
 */
final readonly class VpsProvisioningAuthorizationRequest
{
    /**
     * @param  array<string, mixed>  $oldResources
     * @param  array<string, mixed>  $newResources
     */
    public function __construct(
        public string $requestId,
        public int $userId,
        public int $toVersionId,
        public VpsAuthorizationInitiator $initiator,
        public ?int $virtualMachineId = null,
        public ?int $fromVersionId = null,
        public array $oldResources = [],
        public array $newResources = [],
    ) {}
}
