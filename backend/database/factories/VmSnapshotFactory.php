<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\VirtualMachine;
use App\Models\VmSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VmSnapshot>
 */
class VmSnapshotFactory extends Factory
{
    protected $model = VmSnapshot::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'virtual_machine_id' => VirtualMachine::factory(),
            'user_id' => function (array $attributes): int {
                if (isset($attributes['virtual_machine_id']) && is_int($attributes['virtual_machine_id'])) {
                    $vm = VirtualMachine::find($attributes['virtual_machine_id']);
                    if ($vm !== null) {
                        return $vm->user_id;
                    }
                }

                return User::factory()->create()->id;
            },
            'name' => 'snap-'.$this->faker->unique()->lexify('??????'),
            'source' => VmSnapshot::SOURCE_MANUAL,
            'status' => VmSnapshot::STATUS_READY,
            'stateful' => false,
            'size_bytes' => null,
            'error' => null,
        ];
    }

    /**
     * Indicate that the snapshot is currently being created.
     */
    public function creating(): static
    {
        return $this->state(fn (): array => [
            'status' => VmSnapshot::STATUS_CREATING,
        ]);
    }

    /**
     * Indicate that the snapshot is ready.
     */
    public function ready(): static
    {
        return $this->state(fn (): array => [
            'status' => VmSnapshot::STATUS_READY,
        ]);
    }

    /**
     * Indicate that the snapshot is being restored.
     */
    public function restoring(): static
    {
        return $this->state(fn (): array => [
            'status' => VmSnapshot::STATUS_RESTORING,
        ]);
    }

    /**
     * Indicate that the snapshot is being deleted.
     */
    public function deleting(): static
    {
        return $this->state(fn (): array => [
            'status' => VmSnapshot::STATUS_DELETING,
        ]);
    }

    /**
     * Indicate that the snapshot failed.
     */
    public function failed(string $error = 'Snapshot operation failed'): static
    {
        return $this->state(fn (): array => [
            'status' => VmSnapshot::STATUS_FAILED,
            'error' => $error,
        ]);
    }

    /**
     * Indicate that the snapshot was created by a schedule.
     */
    public function scheduled(): static
    {
        return $this->state(fn (): array => [
            'source' => VmSnapshot::SOURCE_SCHEDULED,
        ]);
    }

    /**
     * Indicate that the snapshot is stateful.
     */
    public function stateful(): static
    {
        return $this->state(fn (): array => [
            'stateful' => true,
        ]);
    }
}
