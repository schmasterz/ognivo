<?php

declare(strict_types=1);

namespace App\Events\Host;

use App\Events\AbstractOgnivoEvent;
use Override;

/**
 * Domain event emitted when a compute host status changes.
 */
final class HostStatusUpdated extends AbstractOgnivoEvent
{
    /**
     * @param  array{type: string, id: string|null, label: string|null}|null  $actor
     */
    public function __construct(
        public readonly int $hostId,
        public readonly string $oldStatus,
        public readonly string $newStatus,
        ?array $actor = null,
    ) {
        parent::__construct(actor: $actor);
    }

    #[Override]
    public function getEventName(): string
    {
        return 'host.status_updated';
    }

    /**
     * @return array{
     *     host_id: int,
     *     old_status: string,
     *     new_status: string,
     * }
     */
    #[Override]
    public function getPayload(): array
    {
        return [
            'host_id' => $this->hostId,
            'old_status' => $this->oldStatus,
            'new_status' => $this->newStatus,
        ];
    }
}
