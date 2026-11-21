<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\VmSnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Override;

/**
 * Model representing a virtual machine or container snapshot.
 *
 * @property int $id
 * @property int $virtual_machine_id
 * @property int $user_id
 * @property string $name
 * @property string $source
 * @property string $status
 * @property int|null $size_bytes
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read VirtualMachine $virtualMachine
 * @property-read User $user
 */
final class VmSnapshot extends Model
{
    /** @use HasFactory<VmSnapshotFactory> */
    use HasFactory;

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_SCHEDULED = 'scheduled';

    public const STATUS_CREATING = 'creating';

    public const STATUS_READY = 'ready';

    public const STATUS_RESTORING = 'restoring';

    public const STATUS_DELETING = 'deleting';

    public const STATUS_FAILED = 'failed';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'vm_snapshots';

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'source' => self::SOURCE_MANUAL,
        'status' => self::STATUS_CREATING,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'virtual_machine_id',
        'user_id',
        'name',
        'source',
        'status',
        'size_bytes',
        'error',
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
            'virtual_machine_id' => 'integer',
            'user_id' => 'integer',
            'size_bytes' => 'integer',
        ];
    }

    /**
     * The virtual machine this snapshot belongs to.
     *
     * @return BelongsTo<VirtualMachine, $this>
     */
    public function virtualMachine(): BelongsTo
    {
        return $this->belongsTo(VirtualMachine::class, 'virtual_machine_id');
    }

    /**
     * The tenant user who owns this snapshot.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
