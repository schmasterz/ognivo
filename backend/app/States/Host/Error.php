<?php

declare(strict_types=1);

namespace App\States\Host;

/**
 * State representing a compute host reporting a general connection or configuration error.
 */
final class Error extends HostState
{
    public static string $name = 'error';
}
