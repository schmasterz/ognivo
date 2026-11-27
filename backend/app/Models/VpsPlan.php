<?php

declare(strict_types=1);

namespace App\Models;

use App\States\VpsPlan\VpsPlanState;
use App\States\VpsPlanVersion\Draft as VersionDraft;
use Database\Factories\VpsPlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Override;
use Spatie\ModelStates\HasStates;

/**
 * Model representing a catalog VPS Plan definition.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property VpsPlanState $status
 * @property int $sort_order
 * @property int|null $current_version_id
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class VpsPlan extends Model
{
    /** @use HasFactory<VpsPlanFactory> */
    use HasFactory;

    use HasStates;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'status',
        'sort_order',
        'current_version_id',
        'created_by',
        'updated_by',
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
            'status' => VpsPlanState::class,
            'sort_order' => 'integer',
            'current_version_id' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    /**
     * All versions belonging to this VPS plan.
     *
     * @return HasMany<VpsPlanVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(VpsPlanVersion::class, 'vps_plan_id');
    }

    /**
     * The currently active published version of this VPS plan.
     *
     * @return BelongsTo<VpsPlanVersion, $this>
     */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(VpsPlanVersion::class, 'current_version_id');
    }

    /**
     * The active draft version of this VPS plan, if any.
     *
     * @return HasOne<VpsPlanVersion, $this>
     */
    public function draftVersion(): HasOne
    {
        return $this->hasOne(VpsPlanVersion::class, 'vps_plan_id')
            ->where('status', VersionDraft::$name);
    }
}
