<?php

declare(strict_types=1);

namespace App\Services\Vps;

use App\Contracts\Data\VpsProvisioningAuthorizationRequest;
use App\Contracts\Data\VpsProvisioningAuthorizationResult;
use App\Contracts\VpsProvisioningAuthorizerInterface;
use App\Enums\Vps\VpsAuthorizationStatus;
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
