<?php

declare(strict_types=1);

namespace App\Services\Vps;

use App\Contracts\Data\VpsPlanChangeAuthorizationRequest;
use App\Contracts\Data\VpsPlanChangeAuthorizationResult;
use App\Contracts\VpsPlanChangeAuthorizerInterface;
use App\Enums\Vps\VpsAuthorizationStatus;
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
