<?php

declare(strict_types=1);

namespace App\Events\Instance;

use App\Events\AbstractOgnivoEvent;
use Override;

/**
 * Domain event emitted when a virtual machine instance specification is resized.
 */
final class InstanceResized extends AbstractOgnivoEvent
{
    /**
     * @param  array{cpu?: int, memory_mb?: int, disk_gb?: int}  $oldSpecs
     * @param  array{cpu?: int, memory_mb?: int, disk_gb?: int}  $newSpecs
     * @param  array{type: string, id: string|null, label: string|null}|null  $actor
     */
    public function __construct(
        public readonly int $instanceId,
        public readonly array $oldSpecs,
        public readonly array $newSpecs,
        ?array $actor = null,
    ) {
        parent::__construct(actor: $actor);
    }

    #[Override]
    public function getEventName(): string
    {
        return 'instance.resized';
    }

    /**
     * @return array{
     *     instance_id: int,
     *     old_specs: array{cpu?: int, memory_mb?: int, disk_gb?: int},
     *     new_specs: array{cpu?: int, memory_mb?: int, disk_gb?: int},
     * }
     */
    #[Override]
    public function getPayload(): array
    {
        return [
            'instance_id' => $this->instanceId,
            'old_specs' => $this->oldSpecs,
            'new_specs' => $this->newSpecs,
        ];
    }
}
