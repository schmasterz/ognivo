<?php

declare(strict_types=1);

namespace App\States\Snapshot;

/**
 * State representing a snapshot ready to be restored or deleted.
 */
final class Ready extends SnapshotState
{
    public static string $name = 'ready';
}
