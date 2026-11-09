<?php

declare(strict_types=1);

namespace App\Models;

use App\Audit\Auditable;
use App\Notifications\QueuedResetPasswordNotification;
use App\Notifications\QueuedVerifyEmailNotification;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Override;
use Spatie\Permission\Traits\HasRoles;

/**
 * Model representing an application user.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $status (active, pending, suspended, inactive)
 * @property string|null $project_name
 * @property int|null $quota_group_id
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read QuotaGroup|null $quotaGroup
 */
final class User extends Authenticatable implements MustVerifyEmail
{
    use Auditable;
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use Notifiable;

    /**
     * Whitelist of attributes tracked for audit logging.
     *
     * @var list<string>
     */
    protected array $auditable = [
        'name',
        'email',
        'status',
        'role',
        'quota_group_id',
    ];

    /**
     * Temporary holder for original role prior to syncRoles.
     */
    public ?string $originalRole = null;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'status',
        'password',
        'project_name',
        'quota_group_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
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
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Quota assigned to this user.
     *
     * @return HasOne<UserQuota, $this>
     */
    public function quota(): HasOne
    {
        return $this->hasOne(UserQuota::class);
    }

    /**
     * Quota group assigned to this user.
     *
     * @return BelongsTo<QuotaGroup, $this>
     */
    public function quotaGroup(): BelongsTo
    {
        return $this->belongsTo(QuotaGroup::class);
    }

    /**
     * Virtual machines owned by this user.
     *
     * @return HasMany<VirtualMachine, $this>
     */
    public function virtualMachines(): HasMany
    {
        return $this->hasMany(VirtualMachine::class);
    }

    /**
     * Snapshots owned by this user.
     *
     * @return HasMany<VmSnapshot, $this>
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(VmSnapshot::class, 'user_id');
    }

    /**
     * Snapshots owned by this user (alias).
     *
     * @return HasMany<VmSnapshot, $this>
     */
    public function vmSnapshots(): HasMany
    {
        return $this->hasMany(VmSnapshot::class, 'user_id');
    }

    /**
     * Ensure this user has an assigned UUID-based project name for hypervisor isolation.
     */
    public function ensureProjectName(): string
    {
        if ($this->project_name !== null && $this->project_name !== '') {
            return $this->project_name;
        }

        $projectName = sprintf('proj-%s', Str::uuid()->toString());
        $this->project_name = $projectName;
        $this->save();

        return $projectName;
    }

    /**
     * IP allocations assigned to or reserved by this user.
     *
     * @return HasMany<IpAllocation, $this>
     */
    public function ipAllocations(): HasMany
    {
        return $this->hasMany(IpAllocation::class);
    }

    /**
     * Send password reset notification asynchronously via Redis queue.
     *
     * @param  string  $token
     */
    #[Override]
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new QueuedResetPasswordNotification($token));
    }

    /**
     * Send email verification notification asynchronously via Redis queue.
     */
    #[Override]
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new QueuedVerifyEmailNotification);
    }
}
