<?php

declare(strict_types=1);

namespace App\States\Vm;

/**
 * State representing a successfully purged VM.
 */
final class Deleted extends VmState
{
    public static string $name = 'deleted';
}
