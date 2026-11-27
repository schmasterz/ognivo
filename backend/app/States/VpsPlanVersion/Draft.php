<?php

declare(strict_types=1);

namespace App\States\VpsPlanVersion;

/**
 * State representing an editable draft VPS plan version.
 */
final class Draft extends VpsPlanVersionState
{
    public static string $name = 'draft';
}
