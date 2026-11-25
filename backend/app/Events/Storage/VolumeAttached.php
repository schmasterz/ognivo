<?php

declare(strict_types=1);

namespace App\Events\Storage;

use App\Events\AbstractOgnivoEvent;
use Override;

/**
 * Domain event emitted when a storage volume is attached to an instance.
 */
final class VolumeAttached extends AbstractOgnivoEvent
{
    /**
     * @param  array{type: string, id: string|null, label: string|null}|null  $actor
     */
    public function __construct(
        public readonly int $volumeId,
        public readonly int $instanceId,
        public readonly string $devicePath,
        ?array $actor = null,
    ) {
        parent::__construct(actor: $actor);
    }

    #[Override]
    public function getEventName(): string
    {
        return 'storage.volume_attached';
    }

    /**
     * @return array{
     *     volume_id: int,
     *     instance_id: int,
     *     device_path: string,
     * }
     */
    #[Override]
    public function getPayload(): array
    {
        return [
            'volume_id' => $this->volumeId,
            'instance_id' => $this->instanceId,
            'device_path' => $this->devicePath,
        ];
    }
}
