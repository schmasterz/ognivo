<?php

declare(strict_types=1);

namespace App\DTO\Auth;

use App\DTO\Cast;

/**
 * Immutable Data Transfer Object for user authentication.
 */
final readonly class LoginDTO
{
    public function __construct(
        public string $email,
        public string $password,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            email: Cast::string($data['email'] ?? ''),
            password: Cast::string($data['password'] ?? ''),
        );
    }
}
