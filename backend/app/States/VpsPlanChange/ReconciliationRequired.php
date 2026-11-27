<?php

declare(strict_types=1);

namespace App\States\VpsPlanChange;

use Override;

/**
 * State representing a plan change whose hypervisor outcome requires reconciliation.
 */
final class ReconciliationRequired extends VpsPlanChangeState
{
    public static string $name = 'reconciliation_required';

    #[Override]
    public function isActive(): bool
    {
        return true;
    }
}
