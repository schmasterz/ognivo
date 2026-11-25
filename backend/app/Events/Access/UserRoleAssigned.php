<?php

declare(strict_types=1);

namespace App\Events\Access;

use App\Events\AbstractOgnivoEvent;
use Override;

/**
 * Domain event emitted when a role is assigned to a user.
 */
final class UserRoleAssigned extends AbstractOgnivoEvent
{
    /**
     * @param  array{type: string, id: string|null, label: string|null}|null  $actor
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $role,
        ?array $actor = null,
    ) {
        parent::__construct(actor: $actor);
    }

    #[Override]
    public function getEventName(): string
    {
        return 'access.user_role_assigned';
    }

    /**
     * @return array{user_id: int, role: string}
     */
    #[Override]
    public function getPayload(): array
    {
        return [
            'user_id' => $this->userId,
            'role' => $this->role,
        ];
    }
}
