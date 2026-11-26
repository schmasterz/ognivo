<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Audit\AuditAction;
use App\Audit\AuditContext;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Audit\AuditOutcome;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\RouteAttributes\Attributes\Middleware;
use Spatie\RouteAttributes\Attributes\Post;
use Spatie\RouteAttributes\Attributes\Prefix;

/**
 * Controller handling user impersonation session lifecycle.
 */
#[Prefix('impersonation')]
#[Middleware(['auth:sanctum', 'active'])]
final class ImpersonationController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly AuditContext $auditContext,
    ) {}

    /**
     * Stop the active impersonation session and restore the administrator browser session.
     */
    #[Post('stop', name: 'impersonation.stop')]
    public function stop(Request $request): JsonResponse
    {
        if (! $request->hasSession()) {
            abort(403, 'Not currently in an impersonation session.');
        }

        /** @var User $user */
        $user = $request->user();

        $impersonatorId = $this->auditContext->impersonatorIdFromRequest($request)
            ?? $this->auditContext->getImpersonatorId();

        if ($impersonatorId === null) {
            abort(403, 'Not currently in an impersonation session.');
        }

        $impersonator = User::find($impersonatorId);
        if ($impersonator === null || $impersonator->status !== 'active' || ! $impersonator->hasRole('admin')) {
            abort(403, 'Impersonator is no longer an active administrator.');
        }

        $this->auditLogger->record(new AuditEntry(
            action: AuditAction::ImpersonationEnded,
            outcome: AuditOutcome::Success,
            subject: $user,
            tenantUserId: $user->id,
            impersonatorId: $impersonatorId,
            visibility: 'tenant',
            context: [
                'impersonator_id' => $impersonatorId,
                'target_user_id' => $user->id,
                'admin_session_issued' => true,
            ],
            pii: [
                'user_email' => $user->email,
            ],
        ));

        Auth::guard('web')->login($impersonator);
        $request->session()->forget(['impersonator_id', 'impersonation_expires_at']);
        $request->session()->regenerate();

        return response()->json([
            'user' => new UserResource($impersonator),
            'message' => 'Impersonation session ended successfully.',
        ]);
    }
}
