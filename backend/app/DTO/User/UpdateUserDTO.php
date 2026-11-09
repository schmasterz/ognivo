<?php

declare(strict_types=1);

namespace App\DTO\User;

use App\DTO\Cast;

/**
 * Immutable Data Transfer Object for updating an existing user account.
 */
final readonly class UpdateUserDTO
{
    public function __construct(
        public string $name,
        public string $email,
        public string $role,
        public ?string $status = null,
        public ?string $password = null,
        public bool $hasQuotaGroupId = false,
        public ?int $quotaGroupId = null,
        public ?CustomQuotaDTO $quota = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $quotaData = Cast::arrayOrNull($data['quota'] ?? null);
        $hasQuotaGroup = array_key_exists('quota_group_id', $data);

        return new self(
            name: Cast::string($data['name'] ?? ''),
            email: Cast::string($data['email'] ?? ''),
            role: Cast::string($data['role'] ?? ''),
            status: Cast::stringOrNull($data['status'] ?? null),
            password: Cast::stringOrNull($data['password'] ?? null),
            hasQuotaGroupId: $hasQuotaGroup,
            quotaGroupId: $hasQuotaGroup ? Cast::intOrNull($data['quota_group_id'] ?? null) : null,
            quota: $quotaData !== null ? CustomQuotaDTO::fromArray($quotaData) : null,
        );
    }
}
