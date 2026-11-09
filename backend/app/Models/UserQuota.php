<?php

declare(strict_types=1);

namespace App\Models;

use App\Audit\Auditable;
use App\Listeners\SyncUserQuotaToHypervisorProjectListener;
use Database\Factories\UserQuotaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Override;

/**
 * Model representing user resource quotas.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $quota_group_id
 * @property int $max_vms
 * @property int $max_cpu_cores
 * @property int $max_ram_mb
 * @property int $max_storage_gb
 * @property int $max_ips
 * @property int|null $max_snapshots
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read QuotaGroup|null $quotaGroup
 */
final class UserQuota extends Model
{
    use Auditable;

    /** @use HasFactory<UserQuotaFactory> */
    use HasFactory;

    /**
     * Whitelist of attributes tracked for audit logging.
     *
     * @var list<string>
     */
    protected array $auditable = [
        'quota_group_id',
        'max_vms',
        'max_cpu_cores',
        'max_ram_mb',
        'max_storage_gb',
        'max_ips',
        'max_snapshots',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'max_snapshots' => 3,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'quota_group_id',
        'max_vms',
        'max_cpu_cores',
        'max_ram_mb',
        'max_storage_gb',
        'max_ips',
        'max_snapshots',
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
            'user_id' => 'integer',
            'quota_group_id' => 'integer',
            'max_vms' => 'integer',
            'max_cpu_cores' => 'integer',
            'max_ram_mb' => 'integer',
            'max_storage_gb' => 'integer',
            'max_ips' => 'integer',
            'max_snapshots' => 'integer',
        ];
    }

    /**
     * The user this quota belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Bootstrap model events to re-sync project limits when quota changes.
     */
    #[Override]
    protected static function booted(): void
    {
        self::saved(SyncUserQuotaToHypervisorProjectListener::class);
    }

    /**
     * The quota group this quota is linked to.
     *
     * @return BelongsTo<QuotaGroup, $this>
     */
    public function quotaGroup(): BelongsTo
    {
        return $this->belongsTo(QuotaGroup::class);
    }
}
