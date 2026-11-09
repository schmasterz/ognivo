<?php

declare(strict_types=1);

namespace App\Models;

use App\Audit\Auditable;
use App\Services\Network\CidrHelper;
use Database\Factories\IpPoolFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Override;

/**
 * Model representing an IP subnet pool (IPv4 or IPv6) under the ranged IPAM architecture.
 *
 * @property int $id
 * @property int $host_id
 * @property string $name
 * @property string $cidr
 * @property string $first_usable
 * @property string $last_usable
 * @property string $network_mode
 * @property string $parent_interface
 * @property string|null $subnet Legacy subnet field
 * @property string|null $gateway
 * @property array<string>|null $dns_servers
 * @property array<string>|null $excluded_ips
 * @property int $ip_version
 * @property int $cooldown_seconds
 * @property bool $is_public
 * @property bool $active
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Host $host
 * @property-read Collection<int, IpAllocation> $allocations
 */
final class IpPool extends Model
{
    use Auditable;

    /** @use HasFactory<IpPoolFactory> */
    use HasFactory;

    /**
     * Whitelist of attributes tracked for audit logging.
     *
     * @var list<string>
     */
    protected array $auditable = [
        'name',
        'description',
        'dns_servers',
        'excluded_ips',
        'cooldown_seconds',
        'active',
        'is_public',
        'gateway',
        'first_usable',
        'last_usable',
        'cidr',
        'network_mode',
        'host_id',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'host_id',
        'name',
        'cidr',
        'first_usable',
        'last_usable',
        'network_mode',
        'parent_interface',
        'subnet',
        'gateway',
        'dns_servers',
        'excluded_ips',
        'ip_version',
        'cooldown_seconds',
        'is_public',
        'active',
        'description',
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
            'host_id' => 'integer',
            'ip_version' => 'integer',
            'cooldown_seconds' => 'integer',
            'is_public' => 'boolean',
            'active' => 'boolean',
            'dns_servers' => 'array',
            'excluded_ips' => 'array',
        ];
    }

    /**
     * Bootstrap model events to keep legacy subnet and cidr synchronized.
     */
    #[Override]
    protected static function booted(): void
    {
        self::saving(function (self $pool): void {
            if (empty($pool->host_id)) {
                $pool->host_id = (int) (Host::where('is_active', true)->value('id') ?? Host::value('id') ?? 1);
            }

            if (empty($pool->subnet) && ! empty($pool->cidr)) {
                $pool->subnet = $pool->cidr;
            } elseif (empty($pool->cidr) && ! empty($pool->subnet)) {
                $pool->cidr = $pool->subnet;
            }

            if ((empty($pool->first_usable) || empty($pool->last_usable)) && ! empty($pool->cidr)) {
                $usable = CidrHelper::calculateUsableRange((string) $pool->cidr);
                if ($usable !== null) {
                    if (empty($pool->first_usable)) {
                        $pool->first_usable = $usable['first'];
                    }
                    if (empty($pool->last_usable)) {
                        $pool->last_usable = $usable['last'];
                    }
                }
            }
        });
    }

    /**
     * IP allocations associated with this pool.
     *
     * @return HasMany<IpAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(IpAllocation::class, 'pool_id');
    }

    /**
     * Host node this pool is assigned to.
     *
     * @return BelongsTo<Host, $this>
     */
    public function host(): BelongsTo
    {
        return $this->belongsTo(Host::class);
    }

    /**
     * Calculate the total count of addressable IPs in the pool.
     */
    public function getTotalIpsAttribute(): int
    {
        $firstLong = ip2long($this->first_usable);
        $lastLong = ip2long($this->last_usable);

        if ($firstLong === false || $lastLong === false || $lastLong < $firstLong) {
            return 0;
        }

        return ($lastLong - $firstLong) + 1;
    }

    /**
     * Calculate current active allocation count.
     */
    public function getActiveAllocationsCountAttribute(): int
    {
        return $this->allocations()->where('status', 'allocated')->count();
    }
}
