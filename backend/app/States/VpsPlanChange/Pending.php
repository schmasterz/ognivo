<?php

declare(strict_types=1);

namespace App\States\VpsPlanChange;

use Override;

/**
 * State representing a plan change queued and ready to be applied.
 */
final class Pending extends VpsPlanChangeState
{
    public static string $name = 'pending';

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
