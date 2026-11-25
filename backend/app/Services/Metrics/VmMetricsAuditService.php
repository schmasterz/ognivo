<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use App\Audit\AuditAction;
use App\Audit\AuditContext;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Audit\AuditOutcome;
use App\Models\User;
use App\Models\VirtualMachine;
use Illuminate\Support\Facades\Cache;

/**
 * Service recording windowed audit entries when an administrator (including while impersonating)
 * views telemetry metrics of an instance owned by another tenant (FR-14).
 */
final class VmMetricsAuditService
{
    public function __construct(
        private readonly ?AuditLogger $auditLogger = null,
        private readonly ?AuditContext $auditContext = null,
        private readonly ?VmMetricsConfigService $config = null,
    ) {}

    /**
     * Record a cross-tenant metrics view audit entry on the first request per (admin, instance) window.
     */
    public function recordIfCrossTenant(User $viewer, VirtualMachine $vm, string $range): bool
    {
        $ctx = $this->auditContext ?? app(AuditContext::class);
        $impersonatorId = $ctx->getImpersonatorId();

        $effectiveAdminId = null;
        if ($impersonatorId !== null) {
            if ($impersonatorId === $vm->user_id) {
                return false;
            }
            $effectiveAdminId = $impersonatorId;
        } elseif ($viewer->hasRole('admin')) {
            if ($viewer->id === $vm->user_id) {
                return false;
            }
            $effectiveAdminId = $viewer->id;
        } else {
            return false;
        }

        $cfg = $this->config ?? app(VmMetricsConfigService::class);
        $windowSeconds = $cfg->auditWindowSeconds();
        $windowKey = sprintf('metrics:audit_win:%d:%d', $effectiveAdminId, $vm->id);

        if (! Cache::add($windowKey, time(), $windowSeconds)) {
            return false;
        }

        $logger = $this->auditLogger ?? app(AuditLogger::class);
        $logger->recordIndependent(new AuditEntry(
            action: AuditAction::VmMetricsViewed,
            outcome: AuditOutcome::Success,
            subject: $vm,
            tenantUserId: $vm->user_id,
            visibility: 'admin',
            context: [
                'range' => $range,
                'window_seconds' => $windowSeconds,
            ],
        ));

        return true;
    }
}
