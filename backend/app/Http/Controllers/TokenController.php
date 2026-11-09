<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Audit\AuditAction;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Audit\AuditOutcome;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\RouteAttributes\Attributes\Delete;
use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Middleware;
use Spatie\RouteAttributes\Attributes\Post;
use Spatie\RouteAttributes\Attributes\Prefix;

/**
 * Controller for tenant personal access token (API credential) management.
 */
#[Prefix('tokens')]
#[Middleware(['auth:sanctum', 'active'])]
final class TokenController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * List personal access tokens for the authenticated user.
     */
    #[Get('', name: 'tokens.index')]
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $tokens = $user->tokens()->select([
            'id', 'name', 'abilities', 'last_used_at', 'expires_at', 'created_at',
        ])->get();

        return response()->json([
            'data' => $tokens,
        ]);
    }

    /**
     * Create a new API credential (personal access token).
     */
    #[Post('', name: 'tokens.store')]
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['nullable', 'array'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $name = $request->string('name')->trim()->value();
        /** @var array<int, string> $abilities */
        $abilities = $request->input('abilities', ['*']);

        $token = $user->createToken($name, $abilities);

        return response()->json([
            'token' => $token->plainTextToken,
            'message' => 'API token created successfully.',
        ], 201);
    }

    /**
     * Revoke a specific personal access token.
     */
    #[Delete('{id}', name: 'tokens.destroy')]
    public function destroy(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $token = $user->tokens()->where('id', $id)->first();

        if ($token !== null) {
            $token->delete();

            $this->auditLogger->record(new AuditEntry(
                action: AuditAction::AuthTokensRevoked,
                outcome: AuditOutcome::Success,
                subject: $user,
                tenantUserId: $user->id,
                visibility: 'tenant',
                context: ['reason' => 'user_action', 'token_id' => $id],
            ));
        }

        return response()->json([
            'message' => 'Token revoked successfully.',
        ]);
    }

    /**
     * Revoke all personal access tokens for the authenticated user.
     */
    #[Delete('', name: 'tokens.destroy_all')]
    public function destroyAll(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->tokens()->delete();

        $this->auditLogger->record(new AuditEntry(
            action: AuditAction::AuthTokensRevoked,
            outcome: AuditOutcome::Success,
            subject: $user,
            tenantUserId: $user->id,
            visibility: 'tenant',
            context: ['reason' => 'user_action'],
        ));

        return response()->json([
            'message' => 'All tokens revoked successfully.',
        ]);
    }
}
