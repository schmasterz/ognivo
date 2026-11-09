<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Model representing infrastructure state drift between Incus and the Database.
 *
 * @property int $id
 * @property int $host_id
 * @property int|null $virtual_machine_id
 * @property string $instance_name
 * @property string $incus_project
 * @property string $kind
 * @property string|null $expected_state
 * @property string|null $actual_state
 * @property array<string, mixed>|null $details
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Host $host
 * @property-read VirtualMachine|null $virtualMachine
 */
final class DriftReport extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'host_id',
        'virtual_machine_id',
        'instance_name',
        'incus_project',
        'kind',
        'expected_state',
        'actual_state',
        'details',
        'resolved_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'details' => 'array',
        'resolved_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Host, $this>
     */
    public function host(): BelongsTo
    {
        return $this->belongsTo(Host::class);
    }

    /**
     * @return BelongsTo<VirtualMachine, $this>
     */
    public function virtualMachine(): BelongsTo
    {
        return $this->belongsTo(VirtualMachine::class);
    }
}
