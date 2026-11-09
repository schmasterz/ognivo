<?php

declare(strict_types=1);

namespace App\DTO\User;

use App\DTO\Cast;

/**
 * Immutable Data Transfer Object for creating a new user account.
 */
final readonly class CreateUserDTO
{
    public function __construct(
        public string $name,
        public string $email,
        public string $password,
        public string $role,
        public string $status = 'active',
        public ?int $quotaGroupId = null,
        public ?CustomQuotaDTO $quota = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $quotaData = Cast::arrayOrNull($data['quota'] ?? null);

        return new self(
            name: Cast::string($data['name'] ?? ''),
            email: Cast::string($data['email'] ?? ''),
            password: Cast::string($data['password'] ?? ''),
            role: Cast::string($data['role'] ?? ''),
            status: Cast::string($data['status'] ?? 'active', 'active'),
            quotaGroupId: Cast::intOrNull($data['quota_group_id'] ?? null),
            quota: $quotaData !== null ? CustomQuotaDTO::fromArray($quotaData) : null,
        );
    }
}
