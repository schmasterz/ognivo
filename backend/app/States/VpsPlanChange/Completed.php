<?php

declare(strict_types=1);

namespace App\States\VpsPlanChange;

/**
 * Terminal state representing a plan change that succeeded.
 */
final class Completed extends VpsPlanChangeState
{
    public static string $name = 'completed';
}
