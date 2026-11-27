<?php

declare(strict_types=1);

namespace App\Services\Vps;

use App\Contracts\VpsPlanChangeAuthorizerInterface;
use App\DTO\Contracts\VpsAuthorizationStatus;
use App\DTO\Contracts\VpsPlanChangeAuthorizationRequest;
use App\DTO\Contracts\VpsPlanChangeAuthorizationResult;
use Override;

/**
 * Default fallback authorizer that unconditionally permits VPS plan-change requests.
 */
final class AllowAllVpsPlanChangeAuthorizer implements VpsPlanChangeAuthorizerInterface
{
    #[Override]
    public function authorize(VpsPlanChangeAuthorizationRequest $request): VpsPlanChangeAuthorizationResult
    {
        return new VpsPlanChangeAuthorizationResult(
            status: VpsAuthorizationStatus::Allowed,
        );
    }
}
