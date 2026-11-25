<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ServiceToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceToken>
 */
class ServiceTokenFactory extends Factory
{
    protected $model = ServiceToken::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => mb_substr($this->faker->unique()->words(2, true), 0, 100),
            'token_hash' => hash('sha256', bin2hex(random_bytes(20))),
            'abilities' => ['*'],
            'last_used_at' => null,
            'expires_at' => null,
            'created_by_user_id' => null,
        ];
    }

    /**
     * Set specific abilities on the generated service token.
     *
     * @param  list<string>  $abilities
     */
    public function withAbilities(array $abilities): static
    {
        return $this->state(fn (): array => [
            'abilities' => $abilities,
        ]);
    }

    /**
     * Indicate that the service token is expired.
     */
    public function expired(): static
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->subDay(),
        ]);
    }
}
