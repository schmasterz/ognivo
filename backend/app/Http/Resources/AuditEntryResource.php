<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/**
 * API Resource transforming an AuditLog for tenant-facing visibility.
 *
 * Redacts admin IPs, external user agents, and filters context payloads
 * to tenant-visible fields only without exposing third-party PII.
 *
 * @property-read AuditLog $resource
 *
 * @mixin AuditLog
 */
final class AuditEntryResource extends JsonResource
{
    /**
     * Whitelist of field names permitted in tenant-visible context.
     *
     * @var array<int, string>
     */
    private const TENANT_VISIBLE_FIELDS = [
        'name',
        'status',
        'state',
        'action',
        'virtual_machine_id',
        'vm_id',
        'snapshot_id',
        'stateful',
        'source',
        'force',
        'forced',
        'cpu_cores',
        'ram_mb',
        'disk_gb',
        'instance_type',
        'os_image',
        'ip',
        'ip_address',
        'ptr',
        'description',
        'detail',
        'details',
        'reason',
        'error',
        'count',
        'previous_state',
        'target_state',
        'filters',
        'row_count',
        'format',
        'size_bytes',
        'role',
        'quota_group_id',
        'spec',
        'email',
        'project_name',
    ];

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        $log = $this->resource;
        /** @var User|null $currentUser */
        $currentUser = $request->user();
        $currentUserId = $currentUser?->id;

        // Determine actor kind: 'you' | 'platform_admin' | 'system'
        if ($log->actor_type === 'system' || $log->actor_type === 'job' || $log->actor_id === null) {
            $kind = 'system';
        } elseif ($log->impersonator_id !== null) {
            $kind = 'platform_admin';
        } elseif ($currentUserId !== null && $log->actor_id === $currentUserId) {
            $kind = 'you';
        } else {
            $kind = 'platform_admin';
        }

        $actor = [
            'kind' => $kind,
        ];

        // Only for impersonation, include impersonator label
        if ($log->impersonator_id !== null) {
            /** @var User|null $impersonator */
            $impersonator = User::find($log->impersonator_id);
            $impersonatorLabel = $impersonator !== null
                ? ($impersonator->email !== '' ? $impersonator->email : ($impersonator->name !== '' ? $impersonator->name : "Admin #{$log->impersonator_id}"))
                : "Admin #{$log->impersonator_id}";
            $actor['impersonator'] = $impersonatorLabel;
            $actor['impersonator_label'] = $impersonatorLabel;
            $actor['display_label'] = 'Platform administrator (support session)';
        }

        // Only display IP and user agent if actor was the tenant user themselves
        $ip = ($kind === 'you') ? $log->ip : null;
        $userAgent = ($kind === 'you') ? $log->user_agent : null;

        $filteredContext = $this->filterTenantContext($log->context, $currentUser, $kind);

        return [
            'id' => $log->id,
            'occurred_at' => $log->occurred_at->toISOString(),
            'action' => is_object($log->action) ? $log->action->value : (string) $log->action,
            'outcome' => is_object($log->outcome) ? $log->outcome->value : (string) $log->outcome,
            'subject_type' => $log->subject_type,
            'subject_id' => $log->subject_id !== null ? (string) $log->subject_id : null,
            'subject_label' => $log->subject_label,
            'actor' => $actor,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'context' => $filteredContext,
        ];
    }

    /**
     * Filter context array to a whitelist of tenant-visible fields.
     * Never leaks third-party PII.
     *
     * @param  array<string, mixed>|null  $context
     * @return array<string, mixed>|null
     */
    private function filterTenantContext(?array $context, ?User $currentUser, string $kind): ?array
    {
        if ($context === null) {
            return null;
        }

        $filtered = [];

        foreach ($context as $key => $value) {
            if ($key === 'pii') {
                // Never include context.pii of other people.
                // Only allow tenant's own PII if the actor was 'you' or affects this user.
                if (is_array($value)) {
                    $cleanPii = [];
                    foreach ($value as $piiKey => $piiValue) {
                        // Allow only user profile personal keys, never attempted_email or third-party emails
                        if (in_array($piiKey, ['name', 'email'], true)) {
                            // If it's a diff array (with old/new)
                            if (is_array($piiValue)) {
                                $cleanPii[$piiKey] = $piiValue;
                            } elseif ($currentUser !== null) {
                                $cleanPii[$piiKey] = $piiValue;
                            }
                        }
                    }
                    if (! empty($cleanPii) && ($kind === 'you' || $this->resource->tenant_user_id === $currentUser?->id)) {
                        $filtered['pii'] = $cleanPii;
                    }
                }

                continue;
            }

            if (in_array($key, ['before', 'after', 'diff', 'spec'], true) && is_array($value)) {
                $subFiltered = [];
                foreach ($value as $subKey => $subValue) {
                    if (in_array($subKey, self::TENANT_VISIBLE_FIELDS, true)) {
                        $subFiltered[$subKey] = $subValue;
                    }
                }
                if (! empty($subFiltered)) {
                    $filtered[$key] = $subFiltered;
                }

                continue;
            }

            if (in_array($key, self::TENANT_VISIBLE_FIELDS, true)) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }
}
