<?php

declare(strict_types=1);

namespace Tests\Unit\Events;

use App\Audit\AuditContext;
use App\Events\AbstractOgnivoEvent;
use App\Events\Contracts\OgnivoEventInterface;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\Auth;
use Override;
use Tests\TestCase;

final class OgnivoEventInterfaceTest extends TestCase
{
    public function test_concrete_event_implements_interface_and_after_commit_contract(): void
    {
        $occurredAt = new DateTimeImmutable('2026-11-25T12:00:00+00:00');
        $actor = [
            'type' => 'user',
            'id' => '42',
            'label' => 'admin@example.com',
        ];
        $payload = [
            'instance_id' => 101,
            'name' => 'web-01',
        ];

        $event = new TestDomainEvent(
            eventName: 'instance.created',
            payload: $payload,
            occurredAt: $occurredAt,
            actor: $actor,
        );

        $this->assertInstanceOf(OgnivoEventInterface::class, $event);
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, $event);
        $this->assertSame('instance.created', $event->getEventName());
        $this->assertSame($payload, $event->getPayload());
        $this->assertSame($occurredAt, $event->getOccurredAt());
        $this->assertSame($actor, $event->getActor());
    }

    public function test_defaults_occurred_at_to_utc_and_actor_to_null_without_context(): void
    {
        $event = new TestDomainEvent('instance.started', ['instance_id' => 7]);

        $this->assertInstanceOf(DateTimeImmutable::class, $event->getOccurredAt());
        $this->assertSame('UTC', $event->getOccurredAt()->getTimezone()->getName());
        $this->assertNull($event->getActor());
    }

    public function test_resolves_actor_from_audit_context_when_actor_is_null(): void
    {
        $context = app(AuditContext::class);
        $context->forSystem();

        $event = new TestDomainEvent('system.maintenance', ['reason' => 'scheduled']);

        $this->assertSame([
            'type' => 'system',
            'id' => null,
            'label' => 'system',
        ], $event->getActor());
    }

    public function test_resolves_actor_from_authenticated_user_when_actor_is_null(): void
    {
        $user = new User([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
        ]);
        $user->id = 99;

        Auth::setUser($user);

        $event = new TestDomainEvent('instance.deleted', ['instance_id' => 9]);

        $this->assertSame([
            'type' => 'user',
            'id' => '99',
            'label' => 'jane@example.com',
        ], $event->getActor());
    }
}

final class TestDomainEvent extends AbstractOgnivoEvent
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array{type: string, id: string|null, label: string|null}|null  $actor
     */
    public function __construct(
        private readonly string $eventName,
        private readonly array $payload,
        ?DateTimeImmutable $occurredAt = null,
        ?array $actor = null,
    ) {
        parent::__construct($occurredAt, $actor);
    }

    #[Override]
    public function getEventName(): string
    {
        return $this->eventName;
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function getPayload(): array
    {
        return $this->payload;
    }
}
