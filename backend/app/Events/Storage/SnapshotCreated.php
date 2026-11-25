<?php

declare(strict_types=1);

namespace App\Events\Storage;

use App\Events\AbstractOgnivoEvent;
use Override;

/**
 * Domain event emitted when an instance snapshot is created.
 */
final class SnapshotCreated extends AbstractOgnivoEvent
{
    /**
     * @param  array{type: string, id: string|null, label: string|null}|null  $actor
     */
    public function __construct(
        public readonly int $snapshotId,
        public readonly int $instanceId,
        public readonly string $name,
        ?array $actor = null,
    ) {
        parent::__construct(actor: $actor);
    }

    #[Override]
    public function getEventName(): string
    {
        return 'storage.snapshot_created';
    }

    /**
     * @return array{
     *     snapshot_id: int,
     *     instance_id: int,
     *     name: string,
     * }
     */
    #[Override]
    public function getPayload(): array
    {
        return [
            'snapshot_id' => $this->snapshotId,
            'instance_id' => $this->instanceId,
            'name' => $this->name,
        ];
    }
}
