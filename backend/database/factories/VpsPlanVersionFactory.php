<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\VpsPlan;
use App\Models\VpsPlanVersion;
use App\States\VpsPlanVersion\Draft;
use App\States\VpsPlanVersion\Published;
use App\States\VpsPlanVersion\Superseded;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VpsPlanVersion>
 */
class VpsPlanVersionFactory extends Factory
{
    protected $model = VpsPlanVersion::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vps_plan_id' => VpsPlan::factory(),
            'version' => null,
            'status' => Draft::class,
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'instance_type' => 'virtual-machine',
            'net_download_mbps' => 1000,
            'net_upload_mbps' => 1000,
            'change_summary' => null,
            'created_by' => null,
            'published_by' => null,
            'created_at' => now(),
            'published_at' => null,
        ];
    }

    /**
     * Indicate that the version is published.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => Published::class,
            'version' => $attributes['version'] ?? $this->faker->unique()->numberBetween(1, 1000000),
            'published_at' => $attributes['published_at'] ?? now(),
        ]);
    }

    /**
     * Indicate that the version is superseded.
     */
    public function superseded(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => Superseded::class,
            'version' => $attributes['version'] ?? $this->faker->unique()->numberBetween(1, 1000000),
            'published_at' => $attributes['published_at'] ?? now()->subDay(),
        ]);
    }
}
