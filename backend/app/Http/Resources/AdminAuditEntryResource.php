<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/**
 * API Resource transforming an AuditLog for administrator visibility.
 *
 * Exposes all audited attributes including inet_ntop converted IP,
 * request_id, operation_id, user_agent, and full redacted context payload.
 *
 * @property-read AuditLog $resource
 *
 * @mixin AuditLog
 */
final class AdminAuditEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        $log = $this->resource;

        return [
            'id' => $log->id,
            'occurred_at' => $log->occurred_at->toISOString(),
            'request_id' => $log->request_id,
            'operation_id' => $log->operation_id,
            'actor_type' => $log->actor_type,
            'actor_id' => $log->actor_id,
            'actor_label' => $log->actor_label,
            'impersonator_id' => $log->impersonator_id,
            'tenant_user_id' => $log->tenant_user_id,
            'action' => is_object($log->action) ? $log->action->value : (string) $log->action,
            'outcome' => is_object($log->outcome) ? $log->outcome->value : (string) $log->outcome,
            'subject_type' => $log->subject_type,
            'subject_id' => $log->subject_id !== null ? (string) $log->subject_id : null,
            'subject_label' => $log->subject_label,
            'ip' => $log->ip,
            'user_agent' => $log->user_agent,
            'visibility' => $log->visibility,
            'context' => $log->context,
        ];
    }
}
