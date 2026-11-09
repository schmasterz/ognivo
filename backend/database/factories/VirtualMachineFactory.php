<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\VirtualMachine;
use App\States\Vm\Running;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VirtualMachine>
 */
class VirtualMachineFactory extends Factory
{
    protected $model = VirtualMachine::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'host_id' => null,
            'name' => 'vm-'.$this->faker->unique()->lexify('??????'),
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'ubuntu:24.04',
        ];
    }
}
