<?php

declare(strict_types=1);

namespace App\States\Vm;

/**
 * State representing a VM undergoing deletion and resource cleanup.
 */
final class Deleting extends VmState
{
    public static string $name = 'deleting';
}
