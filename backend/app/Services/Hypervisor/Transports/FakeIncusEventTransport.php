<?php

declare(strict_types=1);

namespace App\Services\Hypervisor\Transports;

use App\Models\Host;
use App\Services\Hypervisor\Contracts\IncusEventTransportInterface;
use Override;

/**
 * Fake in-memory event transport for automated testing.
 */
class FakeIncusEventTransport implements IncusEventTransportInterface
{
    /**
     * @var list<array<string, mixed>>
     */
    protected static array $queuedEvents = [];

    /**
     * @var (callable(array<string, mixed>): void)|null
     */
    protected static mixed $activeCallback = null;

    /**
     * Push a simulated lifecycle event to the queue or trigger it immediately if listening.
     *
     * @param  array<string, mixed>  $event
     */
    public static function pushEvent(array $event): void
    {
        if (self::$activeCallback !== null) {
            (self::$activeCallback)($event);
        } else {
            self::$queuedEvents[] = $event;
        }
    }

    /**
     * Set the queue of events.
     *
     * @param  list<array<string, mixed>>  $events
     */
    public static function setEvents(array $events): void
    {
        self::$queuedEvents = $events;
    }

    /**
     * Reset queued events and listeners.
     */
    public static function reset(): void
    {
        self::$queuedEvents = [];
        self::$activeCallback = null;
    }

    #[Override]
    public function listen(Host $host, callable $onEvent, ?callable $shouldStop = null): void
    {
        self::$activeCallback = $onEvent;

        // Process all currently queued events
        while (! empty(self::$queuedEvents)) {
            if ($shouldStop !== null && $shouldStop()) {
                break;
            }

            $event = array_shift(self::$queuedEvents);
            $onEvent($event);
        }
    }
}
