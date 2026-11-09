<?php

declare(strict_types=1);

namespace App\States\Vm;

/**
 * State representing a VM operation failure.
 */
final class Failed extends VmState
{
    public static string $name = 'failed';
}
