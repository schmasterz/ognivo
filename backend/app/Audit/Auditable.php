<?php

declare(strict_types=1);

namespace App\Audit;

use App\DTO\Cast;
use App\Models\Host;
use App\Models\IpPool;
use App\Models\QuotaGroup;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserQuota;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;

/**
 * Trait providing automatic update audit logging with whitelisted attribute diffing.
 *
 * @property list<string> $auditable
 */
trait Auditable
{
    /**
     * Map of models to explicit AuditAction for updates.
     *
     * @var array<class-string<Model>, AuditAction>
     */
    protected static array $auditModelActionMap = [
        User::class => AuditAction::UserUpdated,
        Host::class => AuditAction::HostUpdated,
        IpPool::class => AuditAction::IpPoolUpdated,
        QuotaGroup::class => AuditAction::QuotaGroupUpdated,
        UserQuota::class => AuditAction::UserQuotaAssigned,
        SystemSetting::class => AuditAction::SettingsUpdated,
    ];

    /**
     * Flag to disable auditing within a closure.
     */
    protected static bool $auditingDisabled = false;

    /**
     * Captured before state for the update lifecycle.
     *
     * @var array<string, mixed>
     */
    protected array $auditBeforeState = [];

    /**
     * Boot the trait for an Eloquent model.
     */
    public static function bootAuditable(): void
    {
        static::updating(function (Model $model): void {
            if (static::$auditingDisabled) {
                return;
            }

            if (method_exists($model, 'captureAuditableBefore')) {
                $model->captureAuditableBefore();
            }
        });

        static::updated(function (Model $model): void {
            if (static::$auditingDisabled) {
                return;
            }

            if (method_exists($model, 'recordAuditableUpdate')) {
                $model->recordAuditableUpdate();
            }
        });
    }

    /**
     * Execute a callback without auditing.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function withoutAuditing(Closure $callback): mixed
    {
        $previous = static::$auditingDisabled;
        static::$auditingDisabled = true;

        try {
            return $callback();
        } finally {
            static::$auditingDisabled = $previous;
        }
    }

    /**
     * Get the whitelisted auditable attribute names.
     *
     * @return list<string>
     */
    public function getAuditableFields(): array
    {
        return $this->auditable;
    }

    /**
     * Capture whitelisted dirty attribute values before save.
     */
    public function captureAuditableBefore(): void
    {
        $this->auditBeforeState = [];
        $fields = $this->getAuditableFields();

        foreach ($fields as $field) {
            if ($field === 'role' && $this instanceof User) {
                /** @var Collection<int, Role> $roles */
                $roles = $this->roles;
                /** @var Role|null $firstRole */
                $firstRole = $roles->first();
                $currentRole = $this->originalRole ?? $firstRole?->name;
                $this->auditBeforeState['role'] = $currentRole;

                continue;
            }

            if ($this->isDirty($field)) {
                $this->auditBeforeState[$field] = $this->getOriginal($field);
            }
        }
    }

    /**
     * Compare before state with after state and record an audit log if changed.
     */
    public function recordAuditableUpdate(): void
    {
        if (empty($this->auditBeforeState)) {
            return;
        }

        $diff = [];
        $pii = [];

        // Special handling for SystemSetting
        if ($this instanceof SystemSetting) {
            $this->buildSystemSettingDiff($diff);
        } else {
            foreach ($this->auditBeforeState as $field => $oldVal) {
                /** @var Collection<int, Role>|null $roles */
                $roles = $this instanceof User ? $this->roles : null;
                /** @var Role|null $firstRole */
                $firstRole = $roles?->first();
                $newVal = $field === 'role' && $this instanceof User
                    ? $firstRole?->name
                    : $this->getAttribute($field);

                if ($field === 'role' && $this instanceof User && $oldVal === $newVal) {
                    // Check if pending role update exists or role didn't change
                    continue;
                }

                if ($oldVal === $newVal) {
                    continue;
                }

                // Never include password or secrets in diff
                if (in_array(strtolower($field), AuditRedactor::REDACTED_KEYS, true)) {
                    continue;
                }

                if ($this instanceof User && in_array(strtolower($field), ['name', 'email', 'attempted_email'], true)) {
                    $pii[$field] = [
                        'old' => $oldVal,
                        'new' => $newVal,
                    ];
                } else {
                    $diff[$field] = [
                        'old' => $oldVal,
                        'new' => $newVal,
                    ];
                    if ($field === 'quota_group_id') {
                        $diff['quota_group'] = [
                            'old' => $oldVal,
                            'new' => $newVal,
                        ];
                    }
                }
            }
        }

        if ($this instanceof User) {
            $this->originalRole = null;
        }

        $this->auditBeforeState = [];

        if (empty($diff) && empty($pii)) {
            return;
        }

        $action = $this->resolveAuditAction();
        $tenantUserId = $this->resolveAuditTenantUserId();
        $visibility = $this->resolveAuditVisibility();

        $entry = new AuditEntry(
            action: $action,
            outcome: AuditOutcome::Success,
            subject: $this,
            tenantUserId: $tenantUserId,
            visibility: $visibility,
            context: ! empty($diff) ? ['diff' => $diff] : [],
            pii: $pii,
        );

        app(AuditLogger::class)->record($entry);
    }

    /**
     * Build diff specifically for SystemSetting key/value pairs.
     *
     * @param  array<string, mixed>  $diff
     */
    protected function buildSystemSettingDiff(array &$diff): void
    {
        $oldVal = $this->auditBeforeState['value'] ?? null;
        $newVal = $this->getAttribute('value');
        $settingKey = Cast::string($this->getAttribute('key'), 'value');

        if (is_array($oldVal) && is_array($newVal)) {
            $allKeys = array_unique(array_merge(array_keys($oldVal), array_keys($newVal)));
            foreach ($allKeys as $k) {
                $kStr = Cast::string($k);
                $kOld = $oldVal[$kStr] ?? null;
                $kNew = $newVal[$kStr] ?? null;

                if ($kOld !== $kNew) {
                    if (str_contains(strtolower($kStr), 'password') || str_contains(strtolower($kStr), 'secret')) {
                        $diff[$kStr] = ['changed' => true];
                    } else {
                        $diff[$kStr] = [
                            'old' => $kOld,
                            'new' => $kNew,
                        ];
                    }
                }
            }
        } elseif ($oldVal !== $newVal) {
            $effectiveKey = $settingKey !== '' ? $settingKey : 'value';
            if (str_contains(strtolower($effectiveKey), 'password') || str_contains(strtolower($effectiveKey), 'secret')) {
                $diff[$effectiveKey] = ['changed' => true];
            } else {
                $diff[$effectiveKey] = [
                    'old' => $oldVal,
                    'new' => $newVal,
                ];
            }
        }
    }

    /**
     * Resolve the AuditAction for this model's update.
     */
    protected function resolveAuditAction(): AuditAction|string
    {
        if (property_exists($this, 'auditAction') && $this->auditAction !== null) {
            return $this->auditAction instanceof AuditAction ? $this->auditAction : Cast::string($this->auditAction);
        }

        foreach (static::$auditModelActionMap as $modelClass => $action) {
            if ($this instanceof $modelClass) {
                return $action;
            }
        }

        return AuditAction::from($this->getTable().'.updated');
    }

    /**
     * Resolve the tenant user ID for this model.
     */
    protected function resolveAuditTenantUserId(): ?int
    {
        if ($this instanceof User) {
            return Cast::int($this->id);
        }

        if (isset($this->user_id) && is_numeric($this->user_id)) {
            return Cast::int($this->user_id);
        }

        return null;
    }

    /**
     * Resolve visibility ('tenant' or 'admin').
     */
    protected function resolveAuditVisibility(): string
    {
        if ($this instanceof User || isset($this->user_id)) {
            return 'tenant';
        }

        return 'admin';
    }
}
