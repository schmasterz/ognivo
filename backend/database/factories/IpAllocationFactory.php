<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\IpAllocationStatus;
use App\Models\IpAllocation;
use App\Models\IpPool;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IpAllocation>
 */
class IpAllocationFactory extends Factory
{
    protected $model = IpAllocation::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $ip = $this->faker->unique()->ipv4();

        return [
            'pool_id' => IpPool::factory(),
            'address' => inet_pton($ip),
            'ip_string' => $ip,
            'user_id' => User::factory(),
            'mac_address' => sprintf('02:54:%02x:%02x:%02x:%02x', rand(0, 255), rand(0, 255), rand(0, 255), rand(0, 255)),
            'status' => IpAllocationStatus::Allocated,
            'allocated_at' => now(),
            'released_at' => null,
        ];
    }
}
