<?php

declare(strict_types=1);

namespace App\States\Snapshot;

/**
 * State representing a snapshot currently being deleted.
 */
final class Deleting extends SnapshotState
{
    public static string $name = 'deleting';
}
