<?php

declare(strict_types=1);

namespace App\DTO\User;

use App\DTO\Cast;

/**
 * Immutable Data Transfer Object for assigning a user to a Quota Group.
 */
final readonly class AssignQuotaGroupDTO
{
    public function __construct(
        public int $quotaGroupId,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            quotaGroupId: Cast::int($data['quota_group_id'] ?? 0),
        );
    }
}
