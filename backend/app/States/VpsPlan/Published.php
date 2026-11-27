<?php

declare(strict_types=1);

namespace App\States\VpsPlan;

/**
 * State representing a published VPS plan visible in the catalog.
 */
final class Published extends VpsPlanState
{
    public static string $name = 'published';
}
