<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTO\Contracts\VpsProvisioningAuthorizationRequest;
use App\DTO\Contracts\VpsProvisioningAuthorizationResult;

/**
 * Contract for pluggable VPS provisioning authorization providers (Developer Preview).
 *
 * @experimental This extension contract is in Developer Preview and may evolve in minor 1.x releases based on feedback from real reference implementations. It will be frozen under full SemVer once proven with core-integrated modules.
 */
interface VpsProvisioningAuthorizerInterface
{
    public function authorize(VpsProvisioningAuthorizationRequest $request): VpsProvisioningAuthorizationResult;
}
