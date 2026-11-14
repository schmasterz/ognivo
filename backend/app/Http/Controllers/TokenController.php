<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Audit\AuditAction;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Audit\AuditOutcome;
use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
    #[Post('', name: 'tokens.store', middleware: ['throttle:10,1'])]
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['nullable', 'array'],
            'abilities.*' => [
                'required',
                'string',
                'min:1',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value)) {
                        return;
                    }

                    $normalized = strtolower(trim($value));
                    if ($normalized === '' || $normalized === 'impersonated' || str_starts_with($normalized, 'impersonator:')) {
                        $fail('The selected ability is reserved or invalid.');
                    }
                },
            ],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $name = $request->string('name')->trim()->value();
        /** @var array<int, string>|null $requestedAbilities */
        $requestedAbilities = $request->input('abilities');
        /** @var array<int, string> $abilities */
        $abilities = is_array($requestedAbilities) ? array_values($requestedAbilities) : ['*'];

        $rawExpiration = config('sanctum.expiration', 1440);
        $maxMinutes = is_numeric($rawExpiration) ? (int) $rawExpiration : 1440;
        if ($maxMinutes <= 0) {
            $maxMinutes = 1440;
        }

        $defaultExpiresAt = Carbon::now()->addMinutes($maxMinutes);
        $expiresAt = $defaultExpiresAt;

        if ($request->filled('expires_at')) {
            $requestedExpiresAt = Carbon::parse($request->string('expires_at')->value());
            $expiresAt = $requestedExpiresAt->isAfter($defaultExpiresAt) ? $defaultExpiresAt : $requestedExpiresAt;
        }

        $token = $user->createToken($name, $abilities, $expiresAt);

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
