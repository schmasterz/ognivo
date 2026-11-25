<?php

declare(strict_types=1);

namespace Ognivo\HelloOgnivo\Listeners;

use App\Events\Instance\InstanceCreated;
use Illuminate\Support\Facades\Log;

/**
 * Reference event listener that logs newly created virtual machine instances.
 */
final class LogInstanceCreationListener
{
    /**
     * Handle the InstanceCreated domain event.
     */
    public function handle(InstanceCreated $event): void
    {
        Log::info('HelloOgnivo: VM instance created: '.$event->name, [
            'instance_id' => $event->instanceId,
        ]);
    }
}
