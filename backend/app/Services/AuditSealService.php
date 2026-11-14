<?php

declare(strict_types=1);

namespace App\Services;

use App\DTO\Cast;
use App\Models\AuditLog;
use App\Models\AuditSeal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Service managing audit seal generation, hash chaining, and cryptographic verification.
 */
final class AuditSealService
{
    /**
     * Resolve the connection name for writing audit seals.
     */
    public static function auditConnection(): string
    {
        return config('database.default') === 'sqlite' ? 'sqlite' : 'audit';
    }

    /**
     * Resolve the connection name for maintenance operations (pruning and pseudonymisation).
     */
    public static function maintenanceConnection(): string
    {
        return config('database.default') === 'sqlite' ? 'sqlite' : 'audit_maintenance';
    }

    /**
     * Recursively canonicalize data with sorted associative array keys.
     */
    private function canonicalizeData(mixed $data): mixed
    {
        if (is_array($data)) {
            $isList = array_is_list($data);
            $result = [];
            if (! $isList) {
                ksort($data, SORT_STRING);
            }
            foreach ($data as $key => $value) {
                $result[$key] = $this->canonicalizeData($value);
            }

            return $result;
        }

        return $data;
    }

    /**
     * Build canonical array for an AuditLog row.
     * EXCLUDES all personal data: actor_label, subject_label, ip, user_agent, and context.pii.
     * Uses fixed key order (sorted alphabetically) and UTC ISO dates with microseconds.
     *
     * @return array<string, mixed>
     */
    public function canonicalizeRow(AuditLog $log): array
    {
        $context = $log->context;
        if (is_array($context)) {
            unset($context['pii']);
            $context = $context === [] ? null : $this->canonicalizeData($context);
        } else {
            $context = null;
        }

        $occurredAt = $log->occurred_at->copy()
            ->utc()
            ->format('Y-m-d\TH:i:s.u\Z');

        $canonical = [
            'action' => is_object($log->action) ? $log->action->value : (string) $log->action,
            'actor_id' => $log->actor_id !== null ? (int) $log->actor_id : null,
            'actor_type' => (string) $log->actor_type,
            'context' => $context,
            'id' => (int) $log->id,
            'impersonator_id' => $log->impersonator_id !== null ? (int) $log->impersonator_id : null,
            'occurred_at' => $occurredAt,
            'operation_id' => $log->operation_id !== null ? (int) $log->operation_id : null,
            'outcome' => is_object($log->outcome) ? $log->outcome->value : (string) $log->outcome,
            'request_id' => $log->request_id !== null ? (string) $log->request_id : null,
            'subject_id' => $log->subject_id !== null ? (string) $log->subject_id : null,
            'subject_type' => $log->subject_type !== null ? (string) $log->subject_type : null,
            'tenant_user_id' => $log->tenant_user_id !== null ? (int) $log->tenant_user_id : null,
            'visibility' => (string) $log->visibility,
        ];

        ksort($canonical, SORT_STRING);

        return $canonical;
    }

    /**
     * Compute SHA-256 hash of a single audit log row in canonical JSON format.
     */
    public function computeSingleRowHash(AuditLog $log): string
    {
        $json = (string) json_encode(
            $this->canonicalizeRow($log),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return hash('sha256', $json);
    }

    /**
     * Compute canonical SHA-256 hash of audit logs within the block range [fromId, toId].
     * Hash formula: sha256(previous_hash . '|' . implode('|', rowHashes))
     * Personal data (actor_label, subject_label, ip, user_agent, context.pii) is excluded.
     */
    public function computeRowHash(int $fromId, int $toId, ?string $previousHash = null): string
    {
        $logs = AuditLog::query()
            ->where('id', '>=', $fromId)
            ->where('id', '<=', $toId)
            ->orderBy('id', 'asc')
            ->get();

        $rowHashes = [];
        foreach ($logs as $log) {
            $rowHashes[] = $this->computeSingleRowHash($log);
        }

        return hash('sha256', ($previousHash ?? '').'|'.implode('|', $rowHashes));
    }

    /**
     * Compute HMAC for a given hash using the configured secret matching keyId.
     */
    public function computeHmac(string $hash, string $keyId): string
    {
        /** @var array<string, string> $keys */
        $keys = (array) config('audit.hmac.keys', []);
        $key = $keys[$keyId] ?? null;

        if ($key === null || $key === '') {
            $key = Cast::string(config('audit.hmac.current_key', ''));
        }

        if ($key === '') {
            $key = Cast::string(config('app.key', 'fallback-audit-seal-key'));
        }

        return hash_hmac('sha256', $hash, $key);
    }

    /**
     * Create and record a new cryptographic seal for an audit block.
     * Seals are written through the dedicated 'audit' connection.
     */
    public function createSeal(int $fromId, int $toId, ?string $previousHash = null, ?string $keyId = null): AuditSeal
    {
        $keyId = $keyId ?? Cast::string(config('audit.hmac.current_key_id', 'v1'));
        $hash = $this->computeRowHash($fromId, $toId, $previousHash);
        $hmac = $this->computeHmac($hash, $keyId);

        $connection = self::auditConnection();

        return AuditSeal::on($connection)->create([
            'from_id' => $fromId,
            'to_id' => $toId,
            'hash' => $hash,
            'previous_hash' => $previousHash,
            'hmac' => $hmac,
            'key_id' => $keyId,
            'sealed_at' => Carbon::now()->utc(),
            'archived' => false,
        ]);
    }

    /**
     * Verify hashes, linkage, and HMAC signatures for seals.
     * Recomputes non-archived seals from rows, and checks hash chain linkage and HMAC
     * (using the key matching key_id) for all seals including archived ones.
     *
     * @return array{ok: bool, checked: int, first_failed_id: int|null}
     */
    public function verifySeals(?int $limit = null): array
    {
        $query = AuditSeal::query()->orderBy('id', 'asc');

        if ($limit !== null && $limit > 0) {
            $ids = AuditSeal::query()->orderBy('id', 'desc')->limit($limit)->pluck('id')->all();
            if (empty($ids)) {
                return [
                    'ok' => true,
                    'checked' => 0,
                    'first_failed_id' => null,
                ];
            }
            $query->whereIn('id', $ids);
        }

        /** @var Collection<int, AuditSeal> $seals */
        $seals = $query->get();

        if ($seals->isEmpty()) {
            return [
                'ok' => true,
                'checked' => 0,
                'first_failed_id' => null,
            ];
        }

        $checked = 0;
        /** @var AuditSeal|null $prevSeal */
        $prevSeal = null;

        /** @var AuditSeal $firstSeal */
        $firstSeal = $seals->first();
        $prevSeal = AuditSeal::where('id', '<', $firstSeal->id)->orderBy('id', 'desc')->first();

        foreach ($seals as $seal) {
            // 1. Verify hash chaining linkage
            if ($prevSeal !== null) {
                if ($seal->previous_hash === null || ! hash_equals($prevSeal->hash, $seal->previous_hash)) {
                    return [
                        'ok' => false,
                        'checked' => $checked,
                        'first_failed_id' => $seal->id,
                    ];
                }
            } else {
                $isFirstOverall = AuditSeal::where('id', '<', $seal->id)->doesntExist();
                if ($isFirstOverall && $seal->previous_hash !== null && $seal->previous_hash !== '') {
                    return [
                        'ok' => false,
                        'checked' => $checked,
                        'first_failed_id' => $seal->id,
                    ];
                }
            }

            // 2. Recompute non-archived seals from database rows
            if (! $seal->archived) {
                $expectedHash = $this->computeRowHash($seal->from_id, $seal->to_id, $seal->previous_hash);
                if (! hash_equals($expectedHash, $seal->hash)) {
                    return [
                        'ok' => false,
                        'checked' => $checked,
                        'first_failed_id' => $seal->id,
                    ];
                }
            }

            // 3. Recompute and verify HMAC using key matching key_id
            $expectedHmac = $this->computeHmac($seal->hash, $seal->key_id);
            if (! hash_equals($expectedHmac, $seal->hmac)) {
                return [
                    'ok' => false,
                    'checked' => $checked,
                    'first_failed_id' => $seal->id,
                ];
            }

            $checked++;
            $prevSeal = $seal;
        }

        return [
            'ok' => true,
            'checked' => $checked,
            'first_failed_id' => null,
        ];
    }
}
