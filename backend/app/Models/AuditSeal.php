<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Model representing a cryptographic seal over a block of audit logs.
 *
 * @property int $id
 * @property int $from_id
 * @property int $to_id
 * @property string $hash
 * @property string|null $previous_hash
 * @property string $hmac
 * @property string $key_id
 * @property Carbon $sealed_at
 * @property bool $archived
 * @property string|null $archive_sha256
 */
final class AuditSeal extends Model
{
    public $timestamps = false;

    protected $table = 'audit_seals';

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'from_id' => 'integer',
        'to_id' => 'integer',
        'sealed_at' => 'datetime',
        'archived' => 'boolean',
    ];
}
