<?php

declare(strict_types=1);

namespace App\States\VpsPlanChange;

use Override;

/**
 * State representing a plan change actively being applied on the hypervisor.
 */
final class Applying extends VpsPlanChangeState
{
    public static string $name = 'applying';

    #[Override]
    public function isActive(): bool
    {
        return true;
    }
}
