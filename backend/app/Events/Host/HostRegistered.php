<?php

declare(strict_types=1);

namespace App\Events\Host;

use App\Events\AbstractOgnivoEvent;
use Override;

/**
 * Domain event emitted when a compute host is registered.
 */
final class HostRegistered extends AbstractOgnivoEvent
{
    /**
     * @param  array{type: string, id: string|null, label: string|null}|null  $actor
     */
    public function __construct(
        public readonly int $hostId,
        public readonly string $name,
        public readonly string $hostname,
        public readonly string $ipAddress,
        ?array $actor = null,
    ) {
        parent::__construct(actor: $actor);
    }

    #[Override]
    public function getEventName(): string
    {
        return 'host.registered';
    }

    /**
     * @return array{
     *     host_id: int,
     *     name: string,
     *     hostname: string,
     *     ip_address: string,
     * }
     */
    #[Override]
    public function getPayload(): array
    {
        return [
            'host_id' => $this->hostId,
            'name' => $this->name,
            'hostname' => $this->hostname,
            'ip_address' => $this->ipAddress,
        ];
    }
}
