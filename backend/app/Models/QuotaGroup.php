<?php

declare(strict_types=1);

namespace App\Models;

use App\Audit\Auditable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Override;

/**
 * Model representing a quota group / tier plan.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property bool $is_default
 * @property int $max_vms
 * @property int $max_cpu_cores
 * @property int $max_ram_mb
 * @property int $max_storage_gb
 * @property int $max_ips
 * @property int|null $max_snapshots
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, User> $users
 * @property-read Collection<int, UserQuota> $userQuotas
 */
final class QuotaGroup extends Model
{
    use Auditable;

    /**
     * Whitelist of attributes tracked for audit logging.
     *
     * @var list<string>
     */
    protected array $auditable = [
        'name',
        'description',
        'is_default',
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
        'name',
        'description',
        'is_default',
        'max_vms',
        'max_cpu_cores',
        'max_ram_mb',
        'max_storage_gb',
        'max_ips',
        'max_snapshots',
    ];

    #[Override]
    protected static function booted(): void
    {
        self::saving(function (self $group): void {
            if ($group->is_default) {
                self::where('id', '!=', $group->id)->update(['is_default' => false]);
            }
        });
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
            'is_default' => 'boolean',
            'max_vms' => 'integer',
            'max_cpu_cores' => 'integer',
            'max_ram_mb' => 'integer',
            'max_storage_gb' => 'integer',
            'max_ips' => 'integer',
            'max_snapshots' => 'integer',
        ];
    }

    /**
     * Users assigned to this quota group.
     *
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * User quotas linked to this group.
     *
     * @return HasMany<UserQuota, $this>
     */
    public function userQuotas(): HasMany
    {
        return $this->hasMany(UserQuota::class);
    }

    /**
     * Retrieve the designated default quota group.
     */
    public static function getDefaultGroup(): ?self
    {
        return self::where('is_default', true)->first() ?? self::first();
    }
}
