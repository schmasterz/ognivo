<?php

declare(strict_types=1);

namespace App\States\VpsPlan;

/**
 * State representing an archived VPS plan hidden from new deployments.
 */
final class Archived extends VpsPlanState
{
    public static string $name = 'archived';
}
