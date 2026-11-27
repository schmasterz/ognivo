<?php

declare(strict_types=1);

namespace App\States\VpsPlanVersion;

/**
 * State representing an immutable published VPS plan version.
 */
final class Published extends VpsPlanVersionState
{
    public static string $name = 'published';
}
