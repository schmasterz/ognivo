<?php

declare(strict_types=1);

namespace App\States\VpsPlanChange;

/**
 * Terminal state representing a plan change that failed.
 */
final class Failed extends VpsPlanChangeState
{
    public static string $name = 'failed';
}
