<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AuditFilterRequest;
use App\Http\Resources\AuditEntryResource;
use App\Models\User;
use App\Services\AuditQueryService;
use Illuminate\Http\JsonResponse;
use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Middleware;
use Spatie\RouteAttributes\Attributes\Prefix;

/**
 * Controller exposing tenant-isolated audit log entries.
 */
#[Prefix('audit')]
#[Middleware(['auth:sanctum', 'active'])]
final class AuditController extends Controller
{
    public function __construct(
        private readonly AuditQueryService $auditQueryService,
    ) {}

    /**
     * List audit events for the authenticated tenant user.
     * Enforces tenant_user_id = current user and visibility = 'tenant'.
     */
    #[Get('', name: 'audit.index')]
    public function index(AuditFilterRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $dto = $request->toDTO();

        $query = $this->auditQueryService->buildQuery(
            filters: $dto,
            forcedTenantUserId: (int) $user->id,
            forcedVisibility: 'tenant',
        );

        $result = $this->auditQueryService->paginate(
            query: $query,
            perPage: $dto->perPage,
            cursor: $dto->cursor,
        );

        return response()->json([
            'data' => AuditEntryResource::collection($result['items']),
            'next_cursor' => $result['next_cursor'],
            'per_page' => $result['per_page'],
            'total' => $result['total'],
        ]);
    }
}
