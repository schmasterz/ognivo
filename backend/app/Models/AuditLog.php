<?php

declare(strict_types=1);

namespace App\Models;

use App\Audit\AuditAction;
use App\Audit\AuditOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Model representing an immutable audit log entry.
 *
 * @property int $id
 * @property Carbon $occurred_at
 * @property string|null $request_id
 * @property int|null $operation_id
 * @property string $actor_type
 * @property int|null $actor_id
 * @property string|null $actor_label
 * @property int|null $impersonator_id
 * @property int|null $tenant_user_id
 * @property AuditAction|string $action
 * @property AuditOutcome|string $outcome
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string|null $subject_label
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string $visibility
 * @property array<string, mixed>|null $context
 */
final class AuditLog extends Model
{
    public $timestamps = false;

    protected $table = 'audit_logs';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'occurred_at' => 'datetime',
        'context' => 'array',
        'actor_id' => 'integer',
        'tenant_user_id' => 'integer',
        'impersonator_id' => 'integer',
        'operation_id' => 'integer',
    ];

    public function getIpAttribute(): ?string
    {
        $raw = $this->attributes['ip'] ?? null;
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $ntop = @inet_ntop($raw);

        return $ntop !== false ? $ntop : null;
    }

    public function setIpAttribute(?string $value): void
    {
        if ($value === null || $value === '') {
            $this->attributes['ip'] = null;

            return;
        }

        $pton = @inet_pton($value);
        $this->attributes['ip'] = $pton !== false ? $pton : $value;
    }
}
