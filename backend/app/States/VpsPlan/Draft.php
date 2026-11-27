<?php

declare(strict_types=1);

namespace App\States\VpsPlan;

/**
 * State representing a draft VPS plan not yet published to users.
 */
final class Draft extends VpsPlanState
{
    public static string $name = 'draft';
}
