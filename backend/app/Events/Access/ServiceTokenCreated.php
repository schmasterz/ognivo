<?php

declare(strict_types=1);

namespace App\Events\Access;

use App\Events\AbstractOgnivoEvent;
use Override;

/**
 * Domain event emitted when a service token is created.
 */
final class ServiceTokenCreated extends AbstractOgnivoEvent
{
    /**
     * @param  array<int, string>  $abilities
     * @param  array{type: string, id: string|null, label: string|null}|null  $actor
     */
    public function __construct(
        public readonly int $tokenId,
        public readonly string $name,
        public readonly array $abilities,
        ?array $actor = null,
    ) {
        parent::__construct(actor: $actor);
    }

    #[Override]
    public function getEventName(): string
    {
        return 'access.service_token_created';
    }

    /**
     * @return array{
     *     token_id: int,
     *     name: string,
     *     abilities: array<int, string>,
     * }
     */
    #[Override]
    public function getPayload(): array
    {
        return [
            'token_id' => $this->tokenId,
            'name' => $this->name,
            'abilities' => $this->abilities,
        ];
    }
}
