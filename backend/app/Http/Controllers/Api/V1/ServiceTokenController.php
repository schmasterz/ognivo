<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Audit\AuditOutcome;
use App\DTO\Cast;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateServiceTokenRequest;
use App\Models\ServiceToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\RouteAttributes\Attributes\Delete;
use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Middleware;
use Spatie\RouteAttributes\Attributes\Post;
use Spatie\RouteAttributes\Attributes\Prefix;
use Spatie\RouteAttributes\Attributes\WhereNumber;

/**
 * Controller managing administrative CRUD operations for external service tokens.
 */
#[Prefix('service-tokens')]
#[Middleware(['auth:sanctum', 'active', 'admin'])]
final class ServiceTokenController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * List all registered service tokens without exposing token hashes.
     */
    #[Get('', name: 'service_tokens.index')]
    public function index(): JsonResponse
    {
        $tokens = ServiceToken::query()
            ->select([
                'id',
                'name',
                'abilities',
                'last_used_at',
                'expires_at',
                'created_at',
                'created_by_user_id',
            ])
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $tokens,
        ]);
    }

    /**
     * Create a new service token and return the one-time plain-text secret.
     */
    #[Post('', name: 'service_tokens.store')]
    public function store(CreateServiceTokenRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        $name = trim(Cast::string($validated['name'] ?? ''));

        /** @var list<string> $abilities */
        $abilities = [];
        $rawAbilities = $validated['abilities'] ?? [];
        if (is_array($rawAbilities)) {
            foreach ($rawAbilities as $ability) {
                $abilities[] = trim(Cast::string($ability));
            }
        }

        $rawExpiresAt = Cast::stringOrNull($validated['expires_at'] ?? null);
        $expiresAt = $rawExpiresAt !== null ? Carbon::parse($rawExpiresAt) : null;

        $creator = $request->user();
        $creatorUser = $creator instanceof User ? $creator : null;

        /** @var array{token: ServiceToken, plain_text_token: string} $result */
        $result = DB::transaction(function () use ($name, $abilities, $expiresAt, $creatorUser): array {
            $generated = ServiceToken::generate(
                name: $name,
                abilities: $abilities,
                expiresAt: $expiresAt,
                creator: $creatorUser,
            );

            $token = $generated['token'];

            $this->auditLogger->record(new AuditEntry(
                action: 'service_token.created',
                outcome: AuditOutcome::Success,
                subject: $token,
                visibility: 'admin',
                context: [
                    'name' => $token->name,
                    'abilities' => $token->abilities,
                    'expires_at' => $token->expires_at?->toIso8601String(),
                ],
            ));

            return $generated;
        });

        return response()->json([
            'token' => $result['token'],
            'plain_text_token' => $result['plain_text_token'],
        ], 201);
    }

    /**
     * Revoke and delete a service token by ID.
     */
    #[Delete('{id}', name: 'service_tokens.destroy')]
    #[WhereNumber('id')]
    public function destroy(int $id): JsonResponse
    {
        /** @var ServiceToken $token */
        $token = ServiceToken::query()->findOrFail($id);

        DB::transaction(function () use ($token): void {
            $token->delete();

            $this->auditLogger->record(new AuditEntry(
                action: 'service_token.revoked',
                outcome: AuditOutcome::Success,
                subject: $token,
                visibility: 'admin',
                context: [
                    'name' => $token->name,
                ],
            ));
        });

        return response()->json([
            'message' => 'Service token revoked successfully.',
        ]);
    }
}
