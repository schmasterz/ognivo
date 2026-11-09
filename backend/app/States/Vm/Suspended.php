<?php

declare(strict_types=1);

namespace App\States\Vm;

/**
 * State representing a paused or suspended VM.
 */
final class Suspended extends VmState
{
    public static string $name = 'suspended';
}
