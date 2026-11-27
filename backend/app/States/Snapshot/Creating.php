<?php

declare(strict_types=1);

namespace App\States\Snapshot;

/**
 * State representing a snapshot currently being created.
 */
final class Creating extends SnapshotState
{
    public static string $name = 'creating';
}
