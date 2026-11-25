<?php

declare(strict_types=1);

namespace App\Events\Storage;

use App\Events\AbstractOgnivoEvent;
use Override;

/**
 * Domain event emitted when a storage volume is created.
 */
final class VolumeCreated extends AbstractOgnivoEvent
{
    /**
     * @param  array{type: string, id: string|null, label: string|null}|null  $actor
     */
    public function __construct(
        public readonly int $volumeId,
        public readonly string $name,
        public readonly int $sizeGb,
        public readonly string $pool,
        ?array $actor = null,
    ) {
        parent::__construct(actor: $actor);
    }

    #[Override]
    public function getEventName(): string
    {
        return 'storage.volume_created';
    }

    /**
     * @return array{
     *     volume_id: int,
     *     name: string,
     *     size_gb: int,
     *     pool: string,
     * }
     */
    #[Override]
    public function getPayload(): array
    {
        return [
            'volume_id' => $this->volumeId,
            'name' => $this->name,
            'size_gb' => $this->sizeGb,
            'pool' => $this->pool,
        ];
    }
}
