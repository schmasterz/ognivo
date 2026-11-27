<?php

declare(strict_types=1);

namespace App\DTO\Auth;

use App\Support\Cast;

/**
 * Immutable Data Transfer Object for user registration.
 */
final readonly class RegisterDTO
{
    public function __construct(
        public string $name,
        public string $email,
        public string $password,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: Cast::string($data['name'] ?? ''),
            email: Cast::string($data['email'] ?? ''),
            password: Cast::string($data['password'] ?? ''),
        );
    }
}
