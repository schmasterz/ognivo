<?php

declare(strict_types=1);

namespace App\Events\Access;

use App\Events\AbstractOgnivoEvent;
use Override;

/**
 * Domain event emitted when a system maintenance window is scheduled.
 */
final class MaintenanceWindowScheduled extends AbstractOgnivoEvent
{
    /**
     * @param  array{type: string, id: string|null, label: string|null}|null  $actor
     */
    public function __construct(
        public readonly string $title,
        public readonly string $startsAt,
        public readonly string $endsAt,
        ?array $actor = null,
    ) {
        parent::__construct(actor: $actor);
    }

    #[Override]
    public function getEventName(): string
    {
        return 'system.maintenance_window_scheduled';
    }

    /**
     * @return array{
     *     title: string,
     *     starts_at: string,
     *     ends_at: string,
     * }
     */
    #[Override]
    public function getPayload(): array
    {
        return [
            'title' => $this->title,
            'starts_at' => $this->startsAt,
            'ends_at' => $this->endsAt,
        ];
    }
}
