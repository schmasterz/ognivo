<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

trait AuditAssertionTrait
{
    /**
     * Assert an audit log row exists with the specified criteria and passes security checks.
     */
    protected function assertAuditRow(
        string $action,
        string $outcome,
        ?int $tenantUserId,
        string $visibility,
        ?string $requestId = null,
    ): object {
        $query = DB::table('audit_logs')
            ->where('action', $action)
            ->where('outcome', $outcome)
            ->where('visibility', $visibility);

        if ($tenantUserId !== null) {
            $query->where('tenant_user_id', $tenantUserId);
        } else {
            $query->whereNull('tenant_user_id');
        }

        if ($requestId !== null) {
            $query->where('request_id', $requestId);
        }

        $row = $query->latest('id')->first();
        $this->assertNotNull($row, "Failed asserting audit_log row exists for action [{$action}] with outcome [{$outcome}].");

        if ($requestId !== null) {
            $this->assertSame($requestId, $row->request_id);
        } else {
            $this->assertNotEmpty($row->request_id, "Audit log row for [{$action}] has empty request_id.");
        }

        if ($row->context !== null) {
            $decoded = json_decode((string) $row->context, true);
            $this->assertIsArray($decoded, "Failed to json_decode context for action [{$action}].");

            // Scan recursively for forbidden keys
            $this->assertNoForbiddenKeys($decoded);

            // Verify personal values only appear under context.pii
            $this->assertPersonalValuesOnlyInPii($decoded);
        }

        return $row;
    }

    /**
     * Recursively scan an array to verify no forbidden sensitive keys exist.
     *
     * @param  array<string, mixed>  $data
     */
    protected function assertNoForbiddenKeys(array $data): void
    {
        $forbidden = [
            'password',
            'password_confirmation',
            'token',
            'trust_token',
            'private_key',
            'server_certificate',
            'smtp_password',
            'secret',
            'authorization',
        ];

        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string) $key);
            if ($lowerKey === 'smtp_password' && $value === ['changed' => true]) {
                continue;
            }

            $this->assertFalse(
                in_array($lowerKey, $forbidden, true),
                "Forbidden key [{$key}] found in audit log context payload."
            );

            if (is_array($value)) {
                $this->assertNoForbiddenKeys($value);
            }
        }
    }

    /**
     * Verify personal values appear ONLY under context.pii.
     *
     * @param  array<string, mixed>  $contextData
     */
    protected function assertPersonalValuesOnlyInPii(array $contextData): void
    {
        $personalKeys = ['attempted_email', 'name', 'email'];

        foreach ($contextData as $k => $v) {
            if ($k === 'pii') {
                continue;
            }

            $lowerKey = strtolower((string) $k);

            // Special exception: host name or pool name is not a personal value
            // but user name/email/attempted_email is personal.
            if ($lowerKey === 'attempted_email') {
                $this->fail("Personal key [{$k}] found outside context.pii.");
            }

            // In diff or context, if it's not a User model, 'name' can be host/pool/quota group name.
            // But if it's 'email', it should never appear outside pii.
            if ($lowerKey === 'email') {
                $this->fail('Personal key [email] found outside context.pii.');
            }

            if (is_array($v)) {
                $this->assertNoPersonalKeysRecursive($v);
            }
        }
    }

    /**
     * Recursively ensure email and attempted_email never appear outside pii.
     *
     * @param  array<string, mixed>  $data
     */
    protected function assertNoPersonalKeysRecursive(array $data): void
    {
        foreach ($data as $k => $v) {
            $lower = strtolower((string) $k);
            if ($lower === 'attempted_email' || $lower === 'email') {
                $this->fail("Personal key [{$k}] found outside context.pii in sub-array.");
            }

            if (is_array($v)) {
                $this->assertNoPersonalKeysRecursive($v);
            }
        }
    }
}
