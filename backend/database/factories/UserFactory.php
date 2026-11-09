<?php

namespace Database\Factories;

use App\Models\User;
use App\Services\AuthService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $credentials = app(AuthService::class)->generateCredentials('password');

        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => $credentials['password'],
            'password_salt' => $credentials['password_salt'],
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Set a specific plaintext password using atomic salt + HMAC pre-hash generation.
     */
    public function withPassword(string $plainPassword): static
    {
        return $this->state(fn (array $attributes): array => app(AuthService::class)->generateCredentials($plainPassword));
    }

    /**
     * Indicate that the model's email address should be unverified and status pending.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
