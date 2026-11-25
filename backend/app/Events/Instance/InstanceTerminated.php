<?php

declare(strict_types=1);

namespace App\Events\Instance;

use App\Events\AbstractOgnivoEvent;
use Override;

/**
 * Domain event emitted when a virtual machine instance is terminated.
 */
final class InstanceTerminated extends AbstractOgnivoEvent
{
    /**
     * @param  array{type: string, id: string|null, label: string|null}|null  $actor
     */
    public function __construct(
        public readonly int $instanceId,
        public readonly string $name,
        ?array $actor = null,
    ) {
        parent::__construct(actor: $actor);
    }

    #[Override]
    public function getEventName(): string
    {
        return 'instance.terminated';
    }

    /**
     * @return array{instance_id: int, name: string}
     */
    #[Override]
    public function getPayload(): array
    {
        return [
            'instance_id' => $this->instanceId,
            'name' => $this->name,
        ];
    }
}
