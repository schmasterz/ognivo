<?php

declare(strict_types=1);

namespace App\States\Vm;

/**
 * State representing a stopped/powered-off VM.
 */
final class Stopped extends VmState
{
    public static string $name = 'stopped';
}
