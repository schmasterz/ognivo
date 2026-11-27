<?php

declare(strict_types=1);

namespace App\States\Host;

/**
 * State representing a compute host that does not trust the panel certificate or lacks a pin.
 */
final class NotTrusted extends HostState
{
    public static string $name = 'not_trusted';
}
