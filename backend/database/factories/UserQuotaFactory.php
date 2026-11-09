<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\UserQuota;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserQuota>
 */
class UserQuotaFactory extends Factory
{
    protected $model = UserQuota::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'quota_group_id' => null,
            'max_vms' => 2,
            'max_cpu_cores' => 4,
            'max_ram_mb' => 4096,
            'max_storage_gb' => 80,
            'max_ips' => 1,
            'max_snapshots' => 3,
        ];
    }
}
