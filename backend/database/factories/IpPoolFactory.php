<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Host;
use App\Models\IpPool;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IpPool>
 */
class IpPoolFactory extends Factory
{
    protected $model = IpPool::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'host_id' => Host::factory(),
            'name' => $this->faker->words(2, true).' Pool',
            'cidr' => '192.168.100.0/24',
            'first_usable' => '192.168.100.2',
            'last_usable' => '192.168.100.254',
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
            'subnet' => '192.168.100.0/24',
            'gateway' => '192.168.100.1',
            'dns_servers' => ['1.1.1.1', '8.8.8.8'],
            'excluded_ips' => ['192.168.100.1'],
            'ip_version' => 4,
            'cooldown_seconds' => 300,
            'is_public' => true,
            'active' => true,
            'description' => $this->faker->sentence(),
        ];
    }
}
