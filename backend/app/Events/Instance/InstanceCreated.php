<?php

declare(strict_types=1);

namespace App\Events\Instance;

use App\Events\AbstractOgnivoEvent;
use Override;

/**
 * Domain event emitted when a virtual machine instance is created.
 */
final class InstanceCreated extends AbstractOgnivoEvent
{
    /**
     * @param  array{type: string, id: string|null, label: string|null}|null  $actor
     */
    public function __construct(
        public readonly int $instanceId,
        public readonly string $name,
        public readonly string $hostname,
        public readonly int $cpu,
        public readonly int $memoryMb,
        public readonly int $diskGb,
        public readonly string $image,
        ?array $actor = null,
    ) {
        parent::__construct(actor: $actor);
    }

    #[Override]
    public function getEventName(): string
    {
        return 'instance.created';
    }

    /**
     * @return array{
     *     instance_id: int,
     *     name: string,
     *     hostname: string,
     *     cpu: int,
     *     memory_mb: int,
     *     disk_gb: int,
     *     image: string,
     * }
     */
    #[Override]
    public function getPayload(): array
    {
        return [
            'instance_id' => $this->instanceId,
            'name' => $this->name,
            'hostname' => $this->hostname,
            'cpu' => $this->cpu,
            'memory_mb' => $this->memoryMb,
            'disk_gb' => $this->diskGb,
            'image' => $this->image,
        ];
    }
}
