<?php

declare(strict_types=1);

namespace App\States\Host;

/**
 * State representing a healthy, trusted, and reachable compute host.
 */
final class Ok extends HostState
{
    public static string $name = 'ok';
}
