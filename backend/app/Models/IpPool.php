<?php

declare(strict_types=1);

namespace App\Models;

use App\Audit\Auditable;
use App\Services\Network\CidrHelper;
use App\Support\Network\NetworkConstants;
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
 * @property array{total: int, allocated: int, cooling: int, free: int, percent: float}|null $utilization
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
     * Compute effective excluded IP addresses as unsigned long integers.
     * Single source of truth for allocation, utilization, address mapping, and preflight.
     *
     * Contract:
     * 1. Input: excluded_ips and gateway.
     * 2. Normalize every entry as valid IPv4 address; ignore invalid or non-string entries.
     * 3. Merge gateway into the set and remove duplicates.
     * 4. Keep only addresses inside [first_usable, last_usable].
     * 5. Never include any address in 169.254.0.0/16.
     * 6. Return map/set of unsigned integers (suitable for O(1) lookup).
     *
     * @return array<int, int> Map of unsigned long => unsigned long
     */
    public function effectiveExcludedLongs(): array
    {
        $firstLong = CidrHelper::ipToUnsigned((string) $this->first_usable);
        $lastLong = CidrHelper::ipToUnsigned((string) $this->last_usable);

        if ($firstLong === null || $lastLong === null || $firstLong > $lastLong) {
            return [];
        }

        [$linkLocalNet, $linkLocalBcast] = CidrHelper::subnetRange(NetworkConstants::LINK_LOCAL_CIDR) ?? [2851995648, 2852061183];

        $candidates = [];

        if ($this->gateway !== null && $this->gateway !== '') {
            $candidates[] = $this->gateway;
        }

        if (is_array($this->excluded_ips)) {
            foreach ($this->excluded_ips as $entry) {
                if ($entry !== '') {
                    $candidates[] = $entry;
                }
            }
        }

        $result = [];

        foreach ($candidates as $ip) {
            $ipLong = CidrHelper::ipToUnsigned($ip);
            if ($ipLong === null) {
                continue;
            }

            if ($ipLong >= $linkLocalNet && $ipLong <= $linkLocalBcast) {
                continue;
            }

            if ($ipLong >= $firstLong && $ipLong <= $lastLong) {
                $result[$ipLong] = $ipLong;
            }
        }

        return $result;
    }
}
