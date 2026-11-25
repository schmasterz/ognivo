<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Audit\AuditAction;
use App\Audit\AuditContext;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Audit\AuditOutcome;
use App\Models\User;
use App\Models\VirtualMachine;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

final class ForbidImpersonationActions
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly AuditContext $auditContext,
    ) {}

    /**
     * Handle an incoming request and forbid sensitive actions during impersonation.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user === null && $request->bearerToken() !== null) {
            /** @var User|null $sanctumUser */
            $sanctumUser = auth('sanctum')->user();
            $user = $sanctumUser;
        }

        $currentToken = $user?->currentAccessToken();
        $isImpersonated = $this->auditContext->getImpersonatorId() !== null
            || ($currentToken instanceof PersonalAccessToken && AuditContext::isImpersonatedToken($currentToken));

        if (! $isImpersonated || $user === null) {
            return $next($request);
        }

        $impersonatorId = $this->auditContext->getImpersonatorId();
        $path = $request->path();

        // 1. Password changes
        if (($request->is('api/v1/me') || $request->is('api/v1/user/password') || $request->is('api/v1/reset-password')) && $request->filled('password')) {
            $this->auditLogger->recordIndependent(new AuditEntry(
                action: AuditAction::AuthPasswordChanged,
                outcome: AuditOutcome::Denied,
                subject: $user,
                tenantUserId: $user->id,
                impersonatorId: $impersonatorId,
                visibility: 'tenant',
                context: [
                    'reason' => 'forbidden_while_impersonating',
                ],
            ));

            abort(403, 'Changing password is forbidden while impersonating.');
        }

        // 2. Email changes
        if ($request->is('api/v1/me') && $request->filled('email')) {
            $newEmail = $request->string('email')->trim()->value();
            if (strcasecmp($user->email, $newEmail) !== 0) {
                $this->auditLogger->recordIndependent(new AuditEntry(
                    action: AuditAction::AuthEmailChanged,
                    outcome: AuditOutcome::Denied,
                    subject: $user,
                    tenantUserId: $user->id,
                    impersonatorId: $impersonatorId,
                    visibility: 'tenant',
                    context: [
                        'reason' => 'forbidden_while_impersonating',
                    ],
                ));

                abort(403, 'Changing email is forbidden while impersonating.');
            }
        }

        // 3. Creating API credentials
        if (($request->is('api/v1/tokens*') || $request->is('api/v1/api-credentials*')) && $request->isMethod('POST')) {
            $this->auditLogger->recordIndependent(new AuditEntry(
                action: AuditAction::AuthTokensRevoked,
                outcome: AuditOutcome::Denied,
                subject: $user,
                tenantUserId: $user->id,
                impersonatorId: $impersonatorId,
                visibility: 'tenant',
                context: [
                    'action' => 'token.create',
                    'reason' => 'forbidden_while_impersonating',
                ],
            ));

            abort(403, 'Creating API credentials is forbidden while impersonating.');
        }

        // 4. Token management (GET, DELETE, etc. on tokens endpoints)
        if ($request->is('api/v1/tokens*') || $request->is('api/v1/api-credentials*')) {
            $this->auditLogger->recordIndependent(new AuditEntry(
                action: AuditAction::AuthTokensRevoked,
                outcome: AuditOutcome::Denied,
                subject: $user,
                tenantUserId: $user->id,
                impersonatorId: $impersonatorId,
                visibility: 'tenant',
                context: [
                    'action' => 'token.manage',
                    'reason' => 'forbidden_while_impersonating',
                ],
            ));

            abort(403, 'Token management is forbidden while impersonating.');
        }

        // 5. Console ticket issuing
        if ($request->is('api/v1/vms/*/console-ticket')
            || $request->is('api/v1/vms/*/console/ticket')
            || ($request->is('api/v1/vms/*/console') && $request->isMethod('POST'))
            || $request->input('action') === 'console.ticket') {
            $vm = $request->route('virtualMachine');
            $this->auditLogger->recordIndependent(new AuditEntry(
                action: AuditAction::VmActionDenied,
                outcome: AuditOutcome::Denied,
                subject: $vm instanceof VirtualMachine ? $vm : $user,
                tenantUserId: $user->id,
                impersonatorId: $impersonatorId,
                visibility: 'tenant',
                context: [
                    'action' => 'console.ticket',
                    'reason' => 'forbidden_while_impersonating',
                ],
            ));

            abort(403, 'Issuing console tickets is forbidden while impersonating.');
        }

        // 6. Deleting the account
        if ($request->isMethod('DELETE') && ($request->is('api/v1/me') || $request->is('api/v1/admin/users*') || $request->is('api/v1/users*'))) {
            $this->auditLogger->recordIndependent(new AuditEntry(
                action: AuditAction::UserDeleted,
                outcome: AuditOutcome::Denied,
                subject: $user,
                tenantUserId: $user->id,
                impersonatorId: $impersonatorId,
                visibility: 'tenant',
                context: [
                    'reason' => 'forbidden_while_impersonating',
                ],
            ));

            abort(403, 'Deleting accounts is forbidden while impersonating.');
        }

        return $next($request);
    }
}
