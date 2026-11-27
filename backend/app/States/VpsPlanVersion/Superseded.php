<?php

declare(strict_types=1);

namespace App\States\VpsPlanVersion;

/**
 * Terminal state representing a published VPS plan version replaced by a newer version.
 */
final class Superseded extends VpsPlanVersionState
{
    public static string $name = 'superseded';
}
