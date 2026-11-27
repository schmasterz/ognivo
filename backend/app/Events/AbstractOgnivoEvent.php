<?php

declare(strict_types=1);

namespace App\Events;

use App\Audit\AuditContext;
use App\Events\Contracts\OgnivoEventInterface;
use App\Models\User;
use App\Support\Cast;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Override;
use Throwable;

/**
 * Base class for Ognivo domain events guaranteeing after-commit dispatch and structured metadata.
 */
abstract class AbstractOgnivoEvent implements OgnivoEventInterface, ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public readonly DateTimeImmutable $occurredAt;

    /**
     * @var array{type: string, id: string|null, label: string|null}|null
     */
    public readonly ?array $actor;

    /**
     * @param  array{type: string, id: string|null, label: string|null}|null  $actor
     */
    public function __construct(
        ?DateTimeImmutable $occurredAt = null,
        ?array $actor = null,
    ) {
        $this->occurredAt = $occurredAt ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->actor = $actor ?? $this->resolveActor();
    }

    #[Override]
    abstract public function getEventName(): string;

    /**
     * @return array<string, mixed>
     */
    #[Override]
    abstract public function getPayload(): array;

    #[Override]
    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    /**
     * @return array{type: string, id: string|null, label: string|null}|null
     */
    #[Override]
    public function getActor(): ?array
    {
        return $this->actor;
    }

    /**
     * Resolve actor metadata from AuditContext or the authenticated user when available.
     *
     * @return array{type: string, id: string|null, label: string|null}|null
     */
    private function resolveActor(): ?array
    {
        if (! function_exists('app')) {
            return null;
        }

        try {
            $app = app();

            if ($app->bound(AuditContext::class)) {
                $context = $app->make(AuditContext::class);
                $actorType = $context->getActorType();
                $actorId = $context->getActorId();
                $actorLabel = $context->getActorLabel();

                if ($actorId !== null || $actorLabel !== null || $actorType !== 'user') {
                    return [
                        'type' => $actorType,
                        'id' => $actorId !== null ? (string) $actorId : null,
                        'label' => $actorLabel,
                    ];
                }
            }

            if ($app->bound('auth')) {
                $user = Auth::user();
                if ($user instanceof User) {
                    $id = Cast::string($user->getAuthIdentifier());
                    $label = $user->email !== '' ? $user->email : $user->name;

                    return [
                        'type' => 'user',
                        'id' => $id !== '' ? $id : null,
                        'label' => $label !== '' ? $label : ($id !== '' ? "User #{$id}" : null),
                    ];
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }
}
