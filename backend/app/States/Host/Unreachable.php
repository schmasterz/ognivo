<?php

declare(strict_types=1);

namespace App\States\Host;

/**
 * State representing a compute host that cannot currently be reached over the network.
 */
final class Unreachable extends HostState
{
    public static string $name = 'unreachable';
}
