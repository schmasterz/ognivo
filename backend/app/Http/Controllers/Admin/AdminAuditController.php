<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Audit\AuditAction;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Audit\AuditOutcome;
use App\Http\Controllers\Controller;
use App\Http\Requests\AuditFilterRequest;
use App\Http\Resources\AdminAuditEntryResource;
use App\Models\AuditLog;
use App\Services\AuditQueryService;
use App\Services\AuditSealService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Middleware;
use Spatie\RouteAttributes\Attributes\Prefix;
use Spatie\RouteAttributes\Attributes\WhereNumber;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Controller managing administrative audit log access, verification, streaming export, and inspection.
 */
#[Prefix('admin/audit')]
#[Middleware(['auth:sanctum', 'active', 'admin'])]
final class AdminAuditController extends Controller
{
    public function __construct(
        private readonly AuditQueryService $auditQueryService,
        private readonly AuditSealService $auditSealService,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Stream export of audit logs in NDJSON or CSV format chunked using lazyById(1000).
     * The export execution itself is recorded as an audit event (audit.exported).
     */
    #[Get('export', name: 'admin.audit.export')]
    public function export(AuditFilterRequest $request): StreamedResponse
    {
        $dto = $request->toDTO();
        $format = strtolower($request->query('format', 'ndjson'));
        if ($format !== 'csv') {
            $format = 'ndjson';
        }

        $query = $this->auditQueryService->buildQuery(filters: $dto);

        $filename = $format === 'csv' ? 'audit_export.csv' : 'audit_export.ndjson';
        $contentType = $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/x-ndjson; charset=UTF-8';

        $headers = [
            'Content-Type' => $contentType,
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'X-Accel-Buffering' => 'no',
        ];

        return response()->stream(function () use ($query, $format, $dto): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            $rowCount = 0;

            if ($format === 'csv') {
                fputcsv($out, [
                    'id',
                    'occurred_at',
                    'request_id',
                    'operation_id',
                    'actor_type',
                    'actor_id',
                    'actor_label',
                    'impersonator_id',
                    'tenant_user_id',
                    'action',
                    'outcome',
                    'subject_type',
                    'subject_id',
                    'subject_label',
                    'ip',
                    'user_agent',
                    'visibility',
                    'context',
                ]);
            }

            try {
                foreach ($query->lazyById(1000) as $log) {
                    $rowCount++;
                    if ($format === 'csv') {
                        fputcsv($out, [
                            $log->id,
                            $log->occurred_at->toISOString(),
                            $log->request_id,
                            $log->operation_id,
                            $log->actor_type,
                            $log->actor_id,
                            $log->actor_label,
                            $log->impersonator_id,
                            $log->tenant_user_id,
                            is_object($log->action) ? $log->action->value : (string) $log->action,
                            is_object($log->outcome) ? $log->outcome->value : (string) $log->outcome,
                            $log->subject_type,
                            $log->subject_id,
                            $log->subject_label,
                            $log->ip,
                            $log->user_agent,
                            $log->visibility,
                            is_array($log->context)
                                ? json_encode($log->context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                                : (string) $log->context,
                        ]);
                    } else {
                        $resource = new AdminAuditEntryResource($log);
                        fwrite($out, (string) json_encode($resource->resolve(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
                    }
                }
            } finally {
                fclose($out);

                // Record audit.exported event
                $this->auditLogger->recordIndependent(new AuditEntry(
                    action: AuditAction::AuditExported,
                    outcome: AuditOutcome::Success,
                    tenantUserId: null,
                    visibility: 'admin',
                    context: [
                        'format' => $format,
                        'filters' => $dto->toArray(),
                        'row_count' => $rowCount,
                    ],
                ));
            }
        }, 200, $headers);
    }

    /**
     * Cryptographically verify the last N non-archived audit seals.
     */
    #[Get('verify', name: 'admin.audit.verify')]
    public function verify(Request $request): JsonResponse
    {
        $limit = max(1, min(500, $request->integer('limit', 50)));
        $result = $this->auditSealService->verifySeals($limit);

        return response()->json($result);
    }

    /**
     * List all audit log entries across tenants and admin operations.
     */
    #[Get('', name: 'admin.audit.index')]
    public function index(AuditFilterRequest $request): JsonResponse
    {
        $dto = $request->toDTO();
        $query = $this->auditQueryService->buildQuery(filters: $dto);

        $result = $this->auditQueryService->paginate(
            query: $query,
            perPage: $dto->perPage,
            cursor: $dto->cursor,
        );

        return response()->json([
            'data' => AdminAuditEntryResource::collection($result['items']),
            'next_cursor' => $result['next_cursor'],
            'per_page' => $result['per_page'],
            'total' => $result['total'],
        ]);
    }

    /**
     * Inspect a single immutable audit log entry.
     */
    #[Get('{id}', name: 'admin.audit.show')]
    #[WhereNumber('id')]
    public function show(int $id): JsonResponse
    {
        $log = AuditLog::findOrFail($id);

        return response()->json([
            'data' => new AdminAuditEntryResource($log),
        ]);
    }
}
