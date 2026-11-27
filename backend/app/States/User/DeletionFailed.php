<?php

declare(strict_types=1);

namespace App\States\User;

/**
 * State representing a user account whose deletion failed on the hypervisor.
 */
final class DeletionFailed extends UserState
{
    public static string $name = 'deletion_failed';
}
