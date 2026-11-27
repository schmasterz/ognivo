<?php

declare(strict_types=1);

namespace App\States\Snapshot;

/**
 * State representing a snapshot currently being restored.
 */
final class Restoring extends SnapshotState
{
    public static string $name = 'restoring';
}
