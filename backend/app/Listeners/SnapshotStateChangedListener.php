<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\SnapshotStatusUpdated;
use App\Models\VmSnapshot;
use Spatie\ModelStates\Events\StateChanged;
use Throwable;

/**
 * Broadcasts SnapshotStatusUpdated whenever a VmSnapshot transitions between states.
 */
final class SnapshotStateChangedListener
{
    public function handle(StateChanged $event): void
    {
        if (! ($event->model instanceof VmSnapshot)) {
            return;
        }

        /** @var VmSnapshot $snapshot */
        $snapshot = $event->model;

        $status = $event->finalState !== null
            ? $event->finalState->getValue()
            : $snapshot->status->getValue();

        try {
            event(new SnapshotStatusUpdated($snapshot, $status));
        } catch (Throwable) {
            // Ignore broadcast errors so state transitions never fail
        }
    }
}
