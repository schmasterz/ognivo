<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\IpAllocationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Override;

/**
 * Model representing an active or cooling IP address allocation.
 *
 * @property int $id
 * @property int $pool_id
 * @property string $address Binary IP address representation (VARBINARY 16)
 * @property string $ip_string Human-readable IP address string
 * @property int|null $virtual_machine_id
 * @property int $user_id
 * @property string|null $mac_address
 * @property string $status Status ('allocated' or 'cooling')
 * @property Carbon $allocated_at
 * @property Carbon|null $released_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read IpPool $pool
 * @property-read VirtualMachine|null $virtualMachine
 * @property-read User $user
 */
final class IpAllocation extends Model
{
    /** @use HasFactory<IpAllocationFactory> */
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'ip_allocations';

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'address',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'pool_id',
        'address',
        'ip_string',
        'virtual_machine_id',
        'user_id',
        'mac_address',
        'status',
        'allocated_at',
        'released_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'pool_id' => 'integer',
            'virtual_machine_id' => 'integer',
            'user_id' => 'integer',
            'allocated_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /**
     * The pool this allocation belongs to.
     *
     * @return BelongsTo<IpPool, $this>
     */
    public function pool(): BelongsTo
    {
        return $this->belongsTo(IpPool::class, 'pool_id');
    }

    /**
     * The virtual machine attached to this allocation, if any.
     *
     * @return BelongsTo<VirtualMachine, $this>
     */
    public function virtualMachine(): BelongsTo
    {
        return $this->belongsTo(VirtualMachine::class, 'virtual_machine_id');
    }

    /**
     * The tenant user owning this allocation.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Scope query to only currently active allocations.
     *
     * @param  Builder<IpAllocation>  $query
     * @return Builder<IpAllocation>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'allocated');
    }

    /**
     * Scope query to allocations currently in the cooling period.
     *
     * @param  Builder<IpAllocation>  $query
     * @return Builder<IpAllocation>
     */
    public function scopeCooling(Builder $query): Builder
    {
        return $query->where('status', 'cooling');
    }

    /**
     * Check if this allocation is still within its cooling quarantine window.
     */
    public function isUnderCooldown(?int $cooldownSeconds = null): bool
    {
        if ($this->status !== 'cooling' || $this->released_at === null) {
            return false;
        }

        $duration = $cooldownSeconds ?? $this->pool?->cooldown_seconds ?? 300;

        return $this->released_at->copy()->addSeconds($duration)->isFuture();
    }

    /**
     * Get remaining cooldown seconds.
     */
    public function getCooldownRemainingSeconds(): int
    {
        if ($this->status !== 'cooling' || $this->released_at === null) {
            return 0;
        }

        $duration = $this->pool?->cooldown_seconds ?? 300;
        $expiry = $this->released_at->copy()->addSeconds($duration);

        return max(0, (int) now()->diffInSeconds($expiry, false));
    }
}
