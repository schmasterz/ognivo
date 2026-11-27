<?php

declare(strict_types=1);

namespace App\Models;

use App\States\VpsPlanChange\VpsPlanChangeState;
use Database\Factories\VpsPlanChangeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Override;
use Spatie\ModelStates\HasStates;

/**
 * Model representing a VPS plan-change operation against a Virtual Machine.
 *
 * @property int $id
 * @property string $public_id
 * @property int $virtual_machine_id
 * @property int $user_id
 * @property int|null $initiated_by
 * @property int|null $from_version_id
 * @property int $to_version_id
 * @property array<string, mixed> $old_resources
 * @property array<string, mixed> $new_resources
 * @property VpsPlanChangeState $status
 * @property string|null $stage
 * @property string|null $authorization_ref
 * @property string|null $driver_name
 * @property string|null $driver_operation_id
 * @property string|null $error_code
 * @property string|null $error_message
 * @property Carbon|null $requested_at
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $apply_deadline
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class VpsPlanChange extends Model
{
    /** @use HasFactory<VpsPlanChangeFactory> */
    use HasFactory;

    use HasStates;
    use HasUlids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'virtual_machine_id',
        'user_id',
        'initiated_by',
        'from_version_id',
        'to_version_id',
        'old_resources',
        'new_resources',
        'status',
        'stage',
        'authorization_ref',
        'driver_name',
        'driver_operation_id',
        'error_code',
        'error_message',
        'requested_at',
        'started_at',
        'completed_at',
        'failed_at',
        'apply_deadline',
    ];

    /**
     * Get the columns that should receive a unique ULID identifier.
     *
     * @return list<string>
     */
    #[Override]
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'virtual_machine_id' => 'integer',
            'user_id' => 'integer',
            'initiated_by' => 'integer',
            'from_version_id' => 'integer',
            'to_version_id' => 'integer',
            'old_resources' => 'array',
            'new_resources' => 'array',
            'status' => VpsPlanChangeState::class,
            'requested_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'apply_deadline' => 'datetime',
        ];
    }

    /**
     * The virtual machine targeted by this plan change.
     *
     * @return BelongsTo<VirtualMachine, $this>
     */
    public function virtualMachine(): BelongsTo
    {
        return $this->belongsTo(VirtualMachine::class, 'virtual_machine_id');
    }

    /**
     * The tenant user owning the virtual machine.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The user or admin who initiated this plan change, if recorded.
     *
     * @return BelongsTo<User, $this>
     */
    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    /**
     * The source VPS plan version before the change, if any.
     *
     * @return BelongsTo<VpsPlanVersion, $this>
     */
    public function fromVersion(): BelongsTo
    {
        return $this->belongsTo(VpsPlanVersion::class, 'from_version_id');
    }

    /**
     * The target VPS plan version for this change.
     *
     * @return BelongsTo<VpsPlanVersion, $this>
     */
    public function toVersion(): BelongsTo
    {
        return $this->belongsTo(VpsPlanVersion::class, 'to_version_id');
    }

    /**
     * Scope query to only active (non-terminal) plan changes.
     *
     * @param  Builder<VpsPlanChange>  $query
     * @return Builder<VpsPlanChange>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', VpsPlanChangeState::activeValues());
    }
}
