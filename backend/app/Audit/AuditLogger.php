<?php

declare(strict_types=1);

namespace App\Audit;

use App\Support\Cast;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AuditLogger
{
    public function __construct(
        private ?AuditContext $auditContext = null,
    ) {
        $this->auditContext = $auditContext ?? app(AuditContext::class);
    }

    /**
     * Record an audit event on the default connection.
     * Joins the active transaction and rolls back if the surrounding transaction fails.
     */
    public function record(AuditEntry $entry): void
    {
        $this->writeToLogSink($entry);
        $this->insertRow(Cast::string(config('database.default', 'sqlite')), $entry);
    }

    /**
     * Record an audit event independently on the dedicated 'audit' connection.
     * Never throws exceptions; logs failures via report() and continues execution.
     */
    public function recordIndependent(AuditEntry $entry): void
    {
        try {
            $this->writeToLogSink($entry);
            $connection = config('database.default') === 'sqlite' ? 'sqlite' : 'audit';
            $this->insertRow($connection, $entry);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Prepare row data and insert into audit_logs table on the specified connection.
     */
    private function insertRow(string $connection, AuditEntry $entry): void
    {
        $ctx = $this->auditContext ?? app(AuditContext::class);

        $occurredAt = Carbon::now();
        $ipString = $ctx->getIp();
        $ipBinary = null;
        if ($ipString !== null && $ipString !== '') {
            $pton = @inet_pton($ipString);
            $ipBinary = $pton !== false ? $pton : null;
        }

        $contextData = $entry->context;
        if (! empty($entry->pii)) {
            $contextData['pii'] = $entry->pii;
        }
        $cleanedContext = AuditRedactor::clean($contextData);
        $contextJson = ! empty($cleanedContext)
            ? json_encode($cleanedContext, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : null;

        $row = [
            'occurred_at' => $occurredAt->format('Y-m-d H:i:s.u'),
            'request_id' => $ctx->getRequestId(),
            'operation_id' => $entry->operationId ?? $ctx->getOperationId(),
            'actor_type' => $entry->actorType ?? $ctx->getActorType(),
            'actor_id' => $entry->actorId ?? $ctx->getActorId(),
            'actor_label' => $entry->actorLabel ?? $ctx->getActorLabel(),
            'impersonator_id' => $entry->impersonatorId ?? $ctx->getImpersonatorId(),
            'tenant_user_id' => $entry->tenantUserId,
            'action' => $entry->getActionValue(),
            'outcome' => $entry->getOutcomeValue(),
            'subject_type' => $entry->getSubjectType(),
            'subject_id' => $entry->getSubjectId(),
            'subject_label' => $entry->getSubjectLabel(),
            'ip' => $ipBinary,
            'user_agent' => $ctx->getUserAgent(),
            'visibility' => $entry->visibility,
            'context' => $contextJson,
        ];

        DB::connection($connection)->table('audit_logs')->insert($row);
    }

    /**
     * Write structured JSON log entry to the 'audit' log channel.
     */
    private function writeToLogSink(AuditEntry $entry): void
    {
        try {
            $ctx = $this->auditContext ?? app(AuditContext::class);

            $contextData = $entry->context;
            if (! empty($entry->pii)) {
                $contextData['pii'] = $entry->pii;
            }
            $cleanedContext = AuditRedactor::clean($contextData);

            $payload = [
                'occurred_at' => Carbon::now()->format('Y-m-d H:i:s.u'),
                'request_id' => $ctx->getRequestId(),
                'operation_id' => $entry->operationId,
                'actor_type' => $entry->actorType ?? $ctx->getActorType(),
                'actor_id' => $entry->actorId ?? $ctx->getActorId(),
                'actor_label' => $entry->actorLabel ?? $ctx->getActorLabel(),
                'impersonator_id' => $entry->impersonatorId ?? $ctx->getImpersonatorId(),
                'tenant_user_id' => $entry->tenantUserId,
                'action' => $entry->getActionValue(),
                'outcome' => $entry->getOutcomeValue(),
                'subject_type' => $entry->getSubjectType(),
                'subject_id' => $entry->getSubjectId(),
                'subject_label' => $entry->getSubjectLabel(),
                'ip' => $ctx->getIp(),
                'user_agent' => $ctx->getUserAgent(),
                'visibility' => $entry->visibility,
                'context' => $cleanedContext,
            ];

            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            Log::channel('audit')->info($json !== false ? $json : '{}');
        } catch (Throwable) {
            // Secondary log sink failure must not disrupt the primary flow or test mocks
        }
    }
}
