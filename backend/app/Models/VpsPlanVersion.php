<?php

declare(strict_types=1);

namespace App\Models;

use App\States\VpsPlanVersion\Draft;
use App\States\VpsPlanVersion\Published;
use App\States\VpsPlanVersion\Superseded;
use App\States\VpsPlanVersion\VpsPlanVersionState;
use Database\Factories\VpsPlanVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;
use Override;
use Spatie\ModelStates\HasStates;

/**
 * Model representing an immutable snapshot version of a VPS Plan.
 *
 * @property int $id
 * @property int $vps_plan_id
 * @property int|null $version
 * @property VpsPlanVersionState $status
 * @property int $cpu_cores
 * @property int $ram_mb
 * @property int $disk_gb
 * @property string $instance_type
 * @property int|null $net_download_mbps
 * @property int|null $net_upload_mbps
 * @property string|null $change_summary
 * @property int|null $created_by
 * @property int|null $published_by
 * @property Carbon|null $created_at
 * @property Carbon|null $published_at
 */
final class VpsPlanVersion extends Model
{
    /** @use HasFactory<VpsPlanVersionFactory> */
    use HasFactory;

    use HasStates;

    public const UPDATED_AT = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'vps_plan_id',
        'version',
        'status',
        'cpu_cores',
        'ram_mb',
        'disk_gb',
        'instance_type',
        'net_download_mbps',
        'net_upload_mbps',
        'change_summary',
        'created_by',
        'published_by',
        'created_at',
        'published_at',
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
            'vps_plan_id' => 'integer',
            'version' => 'integer',
            'status' => VpsPlanVersionState::class,
            'cpu_cores' => 'integer',
            'ram_mb' => 'integer',
            'disk_gb' => 'integer',
            'net_download_mbps' => 'integer',
            'net_upload_mbps' => 'integer',
            'created_by' => 'integer',
            'published_by' => 'integer',
            'created_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /**
     * The parent VPS plan definition.
     *
     * @return BelongsTo<VpsPlan, $this>
     */
    public function vpsPlan(): BelongsTo
    {
        return $this->belongsTo(VpsPlan::class, 'vps_plan_id');
    }

    /**
     * Virtual machines provisioned or resized against this plan version.
     *
     * @return HasMany<VirtualMachine, $this>
     */
    public function virtualMachines(): HasMany
    {
        return $this->hasMany(VirtualMachine::class, 'vps_plan_version_id');
    }

    /**
     * Enforce immutability once a plan version leaves the draft state.
     */
    #[Override]
    protected static function booted(): void
    {
        self::updating(function (self $version): void {
            $originalStatus = $version->getRawOriginal('status');

            if ($originalStatus === Draft::$name) {
                return;
            }

            if ($originalStatus === Published::$name && $version->getDirty() === ['status' => Superseded::$name]) {
                return;
            }

            throw new LogicException('Published or superseded VPS plan versions are immutable.');
        });

        self::deleting(function (self $version): void {
            if ($version->getRawOriginal('status') !== Draft::$name) {
                throw new LogicException('Only draft VPS plan versions may be deleted.');
            }
        });
    }
}
