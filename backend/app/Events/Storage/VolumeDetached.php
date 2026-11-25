<?php

declare(strict_types=1);

namespace App\Events\Storage;

use App\Events\AbstractOgnivoEvent;
use Override;

/**
 * Domain event emitted when a storage volume is detached from an instance.
 */
final class VolumeDetached extends AbstractOgnivoEvent
{
    /**
     * @param  array{type: string, id: string|null, label: string|null}|null  $actor
     */
    public function __construct(
        public readonly int $volumeId,
        public readonly int $instanceId,
        ?array $actor = null,
    ) {
        parent::__construct(actor: $actor);
    }

    #[Override]
    public function getEventName(): string
    {
        return 'storage.volume_detached';
    }

    /**
     * @return array{volume_id: int, instance_id: int}
     */
    #[Override]
    public function getPayload(): array
    {
        return [
            'volume_id' => $this->volumeId,
            'instance_id' => $this->instanceId,
        ];
    }
}
