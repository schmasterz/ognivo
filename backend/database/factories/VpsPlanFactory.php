<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\VpsPlan;
use App\States\VpsPlan\Archived;
use App\States\VpsPlan\Draft;
use App\States\VpsPlan\Published;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<VpsPlan>
 */
class VpsPlanFactory extends Factory
{
    protected $model = VpsPlan::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = 'Plan '.$this->faker->unique()->bothify('???-###');

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => $this->faker->sentence(),
            'status' => Draft::class,
            'sort_order' => 0,
            'current_version_id' => null,
            'created_by' => null,
            'updated_by' => null,
        ];
    }

    /**
     * Indicate that the plan is published.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => Published::class,
        ]);
    }

    /**
     * Indicate that the plan is archived.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => Archived::class,
        ]);
    }
}
