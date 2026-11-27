<?php

declare(strict_types=1);

namespace App\States\VpsPlanChange;

use Override;

/**
 * State representing a plan change waiting on external authorization or approval.
 */
final class AwaitingAuthorization extends VpsPlanChangeState
{
    public static string $name = 'awaiting_authorization';

    #[Override]
    public function isActive(): bool
    {
        return true;
    }

    #[Override]
    public function canCancel(): bool
    {
        return true;
    }
}
