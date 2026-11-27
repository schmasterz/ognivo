<?php

declare(strict_types=1);

namespace App\States\User;

/**
 * State representing an inactive user account.
 */
final class Inactive extends UserState
{
    public static string $name = 'inactive';
}
