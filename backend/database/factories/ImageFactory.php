<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Image;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Image>
 */
final class ImageFactory extends Factory
{
    protected $model = Image::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word().'/'.fake()->numerify('#.##'),
            'source' => 'https://images.linuxcontainers.org',
            'protocol' => 'simplestreams',
            'os_type' => 'linux',
            'enabled' => true,
        ];
    }
}
