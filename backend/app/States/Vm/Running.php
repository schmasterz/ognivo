<?php

declare(strict_types=1);

namespace App\States\Vm;

/**
 * State representing an active running VM.
 */
final class Running extends VmState
{
    public static string $name = 'running';
}
