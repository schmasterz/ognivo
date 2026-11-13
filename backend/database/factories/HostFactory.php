<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Host;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Host>
 */
class HostFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->slug(),
            'region' => fake()->city(),
            'endpoint_url' => 'https://'.fake()->ipv4().':8443',
            'driver' => 'fake',
            'status' => 'ok',
            'needs_retrust' => false,
            'is_active' => true,
            'total_cpu_cores' => 32,
            'total_ram_mb' => 65536,
            'total_storage_gb' => 2000,
            'capacity_source' => 'discovered',
            'capacity_synced_at' => now(),
        ];
    }
}
