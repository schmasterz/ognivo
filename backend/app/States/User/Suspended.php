<?php

declare(strict_types=1);

namespace App\States\User;

/**
 * State representing a suspended user account.
 */
final class Suspended extends UserState
{
    public static string $name = 'suspended';
}
