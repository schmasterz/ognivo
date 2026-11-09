<?php

declare(strict_types=1);

namespace App\States\Vm;

/**
 * State representing a VM currently being created or provisioned.
 */
final class Provisioning extends VmState
{
    public static string $name = 'provisioning';
}
