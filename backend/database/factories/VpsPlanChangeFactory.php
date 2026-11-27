<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\VirtualMachine;
use App\Models\VpsPlanChange;
use App\Models\VpsPlanVersion;
use App\States\VpsPlanChange\Applying;
use App\States\VpsPlanChange\AwaitingAuthorization;
use App\States\VpsPlanChange\Cancelled;
use App\States\VpsPlanChange\Completed;
use App\States\VpsPlanChange\Failed;
use App\States\VpsPlanChange\Pending;
use App\States\VpsPlanChange\ReconciliationRequired;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<VpsPlanChange>
 */
class VpsPlanChangeFactory extends Factory
{
    protected $model = VpsPlanChange::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'public_id' => strtolower((string) Str::ulid()),
            'virtual_machine_id' => VirtualMachine::factory(),
            'user_id' => User::factory(),
            'initiated_by' => null,
            'from_version_id' => null,
            'to_version_id' => VpsPlanVersion::factory()->published(),
            'old_resources' => [
                'cpu_cores' => 2,
                'ram_mb' => 2048,
                'disk_gb' => 20,
                'net_download_mbps' => 1000,
                'net_upload_mbps' => 1000,
            ],
            'new_resources' => [
                'cpu_cores' => 4,
                'ram_mb' => 4096,
                'disk_gb' => 40,
                'net_download_mbps' => 2000,
                'net_upload_mbps' => 2000,
            ],
            'status' => Pending::class,
            'stage' => null,
            'authorization_ref' => null,
            'driver_name' => null,
            'driver_operation_id' => null,
            'error_code' => null,
            'error_message' => null,
            'requested_at' => now(),
            'started_at' => null,
            'completed_at' => null,
            'failed_at' => null,
            'apply_deadline' => null,
        ];
    }

    /**
     * Indicate that the plan change is awaiting external authorization.
     */
    public function awaitingAuthorization(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AwaitingAuthorization::class,
            'stage' => 'authorization',
        ]);
    }

    /**
     * Indicate that the plan change is currently applying.
     */
    public function applying(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => Applying::class,
            'stage' => 'applying',
            'started_at' => $attributes['started_at'] ?? now(),
        ]);
    }

    /**
     * Indicate that the plan change completed successfully.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => Completed::class,
            'stage' => 'committing',
            'started_at' => $attributes['started_at'] ?? now()->subMinute(),
            'completed_at' => $attributes['completed_at'] ?? now(),
        ]);
    }

    /**
     * Indicate that the plan change failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => Failed::class,
            'failed_at' => $attributes['failed_at'] ?? now(),
            'error_code' => $attributes['error_code'] ?? 'APPLY_FAILED',
            'error_message' => $attributes['error_message'] ?? 'Plan change failed.',
        ]);
    }

    /**
     * Indicate that the plan change was cancelled.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => Cancelled::class,
        ]);
    }

    /**
     * Indicate that the plan change requires reconciliation.
     */
    public function reconciliationRequired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ReconciliationRequired::class,
            'stage' => 'verifying',
            'started_at' => $attributes['started_at'] ?? now()->subMinute(),
        ]);
    }
}
