<?php

declare(strict_types=1);

namespace App\Events\Host;

use App\Events\AbstractOgnivoEvent;
use Override;

/**
 * Domain event emitted when a compute host exits maintenance mode.
 */
final class HostMaintenanceExited extends AbstractOgnivoEvent
{
    /**
     * @param  array{type: string, id: string|null, label: string|null}|null  $actor
     */
    public function __construct(
        public readonly int $hostId,
        ?array $actor = null,
    ) {
        parent::__construct(actor: $actor);
    }

    #[Override]
    public function getEventName(): string
    {
        return 'host.maintenance_exited';
    }

    /**
     * @return array{host_id: int}
     */
    #[Override]
    public function getPayload(): array
    {
        return [
            'host_id' => $this->hostId,
        ];
    }
}
