<?php

declare(strict_types=1);

namespace App\States\Snapshot;

/**
 * State representing a snapshot whose last operation failed.
 */
final class Failed extends SnapshotState
{
    public static string $name = 'failed';
}
