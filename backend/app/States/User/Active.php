<?php

declare(strict_types=1);

namespace App\States\User;

/**
 * State representing an active user account.
 */
final class Active extends UserState
{
    public static string $name = 'active';
}
