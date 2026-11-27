<?php

declare(strict_types=1);

namespace App\States\User;

/**
 * State representing a user awaiting email verification.
 */
final class Pending extends UserState
{
    public static string $name = 'pending';
}
