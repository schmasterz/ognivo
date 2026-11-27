<?php

declare(strict_types=1);

namespace App\States\User;

/**
 * State representing a user account undergoing asynchronous deletion.
 */
final class Deleting extends UserState
{
    public static string $name = 'deleting';
}
