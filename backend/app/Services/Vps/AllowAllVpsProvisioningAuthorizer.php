<?php

declare(strict_types=1);

namespace App\Services\Vps;

use App\Contracts\VpsProvisioningAuthorizerInterface;
use App\DTO\Contracts\VpsAuthorizationStatus;
use App\DTO\Contracts\VpsProvisioningAuthorizationRequest;
use App\DTO\Contracts\VpsProvisioningAuthorizationResult;
use Override;

/**
 * Default fallback authorizer that unconditionally permits VPS provisioning requests.
 */
final class AllowAllVpsProvisioningAuthorizer implements VpsProvisioningAuthorizerInterface
{
    #[Override]
    public function authorize(VpsProvisioningAuthorizationRequest $request): VpsProvisioningAuthorizationResult
    {
        return new VpsProvisioningAuthorizationResult(
            status: VpsAuthorizationStatus::Allowed,
        );
    }
}
