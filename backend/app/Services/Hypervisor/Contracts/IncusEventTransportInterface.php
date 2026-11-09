<?php

declare(strict_types=1);

namespace App\Services\Hypervisor\Contracts;

use App\Models\Host;

/**
 * Interface contract for listening to lifecycle events on an Incus/LXD host.
 */
interface IncusEventTransportInterface
{
    /**
     * Connect to the host event stream and invoke the callback for each lifecycle event.
     *
     * @param  callable(array<string, mixed>): void  $onEvent
     * @param  (callable(): bool)|null  $shouldStop  Optional cancellation callback
     */
    public function listen(Host $host, callable $onEvent, ?callable $shouldStop = null): void;
}
