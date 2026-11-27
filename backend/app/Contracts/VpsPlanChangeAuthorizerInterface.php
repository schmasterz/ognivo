<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Contracts\Data\VpsPlanChangeAuthorizationRequest;
use App\Contracts\Data\VpsPlanChangeAuthorizationResult;

/**
 * Contract for pluggable VPS plan-change authorization providers (Developer Preview).
 *
 * @experimental This extension contract is in Developer Preview and may evolve in minor 1.x releases based on feedback from real reference implementations. It will be frozen under full SemVer once proven with core-integrated modules.
 */
interface VpsPlanChangeAuthorizerInterface
{
    public function authorize(VpsPlanChangeAuthorizationRequest $request): VpsPlanChangeAuthorizationResult;
}
