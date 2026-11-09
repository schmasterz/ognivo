<?php

declare(strict_types=1);

namespace App\Models;

use App\Audit\Auditable;
use Database\Factories\HostFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Override;

/**
 * Model representing an Incus or virtualization host node.
 *
 * @property int $id
 * @property string $name
 * @property string|null $region
 * @property string $endpoint_url
 * @property string|null $server_certificate
 * @property string|null $server_fingerprint
 * @property string|null $server_public_key_pin
 * @property string $driver
 * @property string|null $token
 * @property string $status
 * @property bool $needs_retrust
 * @property Carbon|null $last_checked_at
 * @property bool $is_active
 * @property int $total_cpu_cores
 * @property int $total_ram_mb
 * @property int $total_storage_gb
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Host extends Model
{
    use Auditable;

    /** @use HasFactory<HostFactory> */
    use HasFactory;

    /**
     * Whitelist of attributes tracked for audit logging.
     *
     * @var list<string>
     */
    protected array $auditable = [
        'name',
        'region',
        'endpoint_url',
        'driver',
        'is_active',
        'total_cpu_cores',
        'total_ram_mb',
        'total_storage_gb',
        'settings',
        'server_fingerprint',
        'server_public_key_pin',
        'status',
        'needs_retrust',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'region',
        'endpoint_url',
        'server_certificate',
        'server_fingerprint',
        'server_public_key_pin',
        'driver',
        'token',
        'status',
        'needs_retrust',
        'last_checked_at',
        'is_active',
        'total_cpu_cores',
        'total_ram_mb',
        'total_storage_gb',
        'settings',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'token',
        'server_certificate',
        'server_public_key_pin',
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
            'is_active' => 'boolean',
            'needs_retrust' => 'boolean',
            'last_checked_at' => 'datetime',
            'total_cpu_cores' => 'integer',
            'total_ram_mb' => 'integer',
            'total_storage_gb' => 'integer',
            'settings' => 'array',
            'token' => 'encrypted',
        ];
    }

    /**
     * Bootstrap model events to attach available images to newly registered compute nodes.
     */
    #[Override]
    protected static function booted(): void
    {
        self::created(function (Host $host): void {
            try {
                $imageIds = Image::pluck('id');
                if ($imageIds->isNotEmpty()) {
                    $host->images()->syncWithoutDetaching($imageIds);
                }
            } catch (\Throwable) {
                // Silently ignore during migrations
            }
        });
    }

    /**
     * Virtual machines provisioned on this host.
     *
     * @return HasMany<VirtualMachine, $this>
     */
    public function virtualMachines(): HasMany
    {
        return $this->hasMany(VirtualMachine::class);
    }

    /**
     * IP Subnet Pools assigned to this host node.
     *
     * @return HasMany<IpPool, $this>
     */
    public function ipPools(): HasMany
    {
        return $this->hasMany(IpPool::class);
    }

    /**
     * Approved OS images available on this compute node.
     *
     * @return BelongsToMany<Image, $this>
     */
    public function images(): BelongsToMany
    {
        return $this->belongsToMany(Image::class, 'host_image')->withTimestamps();
    }

    /**
     * State drift reports recorded for this host node.
     *
     * @return HasMany<DriftReport, $this>
     */
    public function driftReports(): HasMany
    {
        return $this->hasMany(DriftReport::class);
    }
}
