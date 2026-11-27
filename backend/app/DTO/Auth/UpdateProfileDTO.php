<?php

declare(strict_types=1);

namespace App\DTO\Auth;

use App\Support\Cast;

/**
 * Immutable Data Transfer Object for profile updates and password modification.
 */
final readonly class UpdateProfileDTO
{
    public function __construct(
        public string $name,
        public string $email,
        public ?string $currentPassword = null,
        public ?string $password = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: Cast::string($data['name'] ?? ''),
            email: Cast::string($data['email'] ?? ''),
            currentPassword: Cast::stringOrNull($data['current_password'] ?? null),
            password: Cast::stringOrNull($data['password'] ?? null),
        );
    }
}
