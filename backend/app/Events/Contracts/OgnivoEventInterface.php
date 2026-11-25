<?php

declare(strict_types=1);

namespace App\Events\Contracts;

use DateTimeImmutable;

/**
 * Contract for all domain events emitted by Ognivo Core.
 */
interface OgnivoEventInterface
{
    /**
     * Unique dot-notation name of the event (e.g. "instance.created").
     */
    public function getEventName(): string;

    /**
     * JSON-serializable structured payload of the event.
     *
     * @return array<string, mixed>
     */
    public function getPayload(): array;

    /**
     * Immutable UTC timestamp when the event occurred.
     */
    public function getOccurredAt(): DateTimeImmutable;

    /**
     * Contextual actor that initiated the action (user, service token, or system).
     *
     * @return array{type: string, id: string|null, label: string|null}|null
     */
    public function getActor(): ?array;
}
