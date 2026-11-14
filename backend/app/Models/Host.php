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
 * @property string|null $capacity_source
 * @property Carbon|null $capacity_synced_at
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
        'capacity_source',
        'capacity_synced_at',
        'settings',
        'server_fingerprint',
        'server_public_key_pin',
        'status',
        'needs_retrust',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'capacity_source' => 'unknown',
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
        'capacity_source',
        'capacity_synced_at',
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
            'capacity_source' => 'string',
            'capacity_synced_at' => 'datetime',
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
        self::creating(function (Host $host): void {
            if ($host->capacity_source === null || $host->capacity_source === '') {
                if (empty($host->total_cpu_cores) && empty($host->total_ram_mb) && empty($host->total_storage_gb)) {
                    if ($host->driver === 'fake' || (bool) app()->environment('local', 'testing')) {
                        $host->total_cpu_cores = 64;
                        $host->total_ram_mb = 131072;
                        $host->total_storage_gb = 4000;
                        $host->capacity_source = 'discovered';
                        $host->capacity_synced_at = Carbon::now();

                        return;
                    }
                }

                $isPlaceholder = $host->total_cpu_cores === 32
                    && $host->total_ram_mb === 65536
                    && $host->total_storage_gb === 2000;

                if ($isPlaceholder) {
                    $host->capacity_source = 'unknown';
                } elseif ($host->total_cpu_cores > 0 || $host->total_ram_mb > 0 || $host->total_storage_gb > 0) {
                    $host->capacity_source = 'discovered';
                    $host->capacity_synced_at ??= Carbon::now();
                } else {
                    $host->capacity_source = 'unknown';
                }
            }
        });

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
