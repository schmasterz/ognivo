<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Audit\AuditAction;
use App\Audit\AuditContext;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Audit\AuditOutcome;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
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
     * Stop active impersonation session, revoking the short-lived impersonation token.
     */
    #[Post('stop', name: 'impersonation.stop')]
    public function stop(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var PersonalAccessToken|null $token */
        $token = $user->currentAccessToken();

        $impersonatorId = $this->auditContext->getImpersonatorId();
        if ($impersonatorId === null && $token !== null) {
            foreach ($token->abilities ?? [] as $ability) {
                if (is_string($ability) && str_starts_with($ability, 'impersonator:')) {
                    $id = substr($ability, 13);
                    if (is_numeric($id)) {
                        $impersonatorId = (int) $id;
                        break;
                    }
                }
            }
            if ($impersonatorId === null && isset($token->name) && str_starts_with((string) $token->name, 'impersonation:')) {
                $id = substr((string) $token->name, 14);
                if (is_numeric($id)) {
                    $impersonatorId = (int) $id;
                }
            }
        }

        if ($token === null || ! $token->can('impersonated')) {
            abort(403, 'Not currently in an impersonation session.');
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
            ],
            pii: [
                'user_email' => $user->email,
            ],
        ));

        $token->delete();

        return response()->json([
            'message' => 'Impersonation session ended successfully.',
        ]);
    }
}
