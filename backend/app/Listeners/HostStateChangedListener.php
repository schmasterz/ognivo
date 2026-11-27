<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\Host\HostStatusUpdated;
use App\Models\Host;
use Illuminate\Contracts\Events\ShouldBeDiscovered;
use Override;
use Spatie\ModelStates\Events\StateChanged;
use Throwable;

/**
 * Dispatches HostStatusUpdated whenever a Host transitions between operational states.
 */
final class HostStateChangedListener implements ShouldBeDiscovered
{
    #[Override]
    public static function shouldBeDiscovered(): bool
    {
        return false;
    }

    public function handle(StateChanged $event): void
    {
        if (! ($event->model instanceof Host)) {
            return;
        }

        /** @var Host $host */
        $host = $event->model;

        $oldStatus = $event->initialState !== null
            ? $event->initialState->getValue()
            : '';

        $newStatus = $event->finalState !== null
            ? $event->finalState->getValue()
            : $host->status->getValue();

        if ($oldStatus === $newStatus) {
            return;
        }

        try {
            event(new HostStatusUpdated($host->id, $oldStatus, $newStatus));
        } catch (Throwable) {
            // Ignore broadcast/dispatch errors so state transitions never fail
        }
    }
}
