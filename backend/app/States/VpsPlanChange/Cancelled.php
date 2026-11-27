<?php

declare(strict_types=1);

namespace App\States\VpsPlanChange;

/**
 * Terminal state representing a plan change cancelled before execution.
 */
final class Cancelled extends VpsPlanChangeState
{
    public static string $name = 'cancelled';
}
