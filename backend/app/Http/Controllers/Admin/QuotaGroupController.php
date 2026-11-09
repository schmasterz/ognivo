<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateQuotaGroupRequest;
use App\Http\Requests\Admin\UpdateDefaultQuotaRequest;
use App\Http\Requests\Admin\UpdateQuotaGroupRequest;
use App\Http\Resources\QuotaGroupResource;
use App\Models\QuotaGroup;
use App\Services\QuotaGroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\RouteAttributes\Attributes\Delete;
use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Middleware;
use Spatie\RouteAttributes\Attributes\Post;
use Spatie\RouteAttributes\Attributes\Prefix;
use Spatie\RouteAttributes\Attributes\Put;

/**
 * Controller managing administrative operations on Quota Groups (resource tiers) and default quota settings.
 */
#[Prefix('admin')]
#[Middleware(['auth:sanctum', 'active', 'admin'])]
final class QuotaGroupController extends Controller
{
    public function __construct(
        private readonly QuotaGroupService $quotaGroupService,
    ) {}

    /**
     * List all quota groups with assigned user counts.
     */
    #[Get('quota-groups', name: 'admin.quota_groups.index')]
    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min(100, $request->integer('per_page', 10)));
        $paginator = $this->quotaGroupService->paginate($perPage);

        return response()->json([
            'data' => QuotaGroupResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ]);
    }

    /**
     * Create a new Quota Group.
     */
    #[Post('quota-groups', name: 'admin.quota_groups.store')]
    public function store(CreateQuotaGroupRequest $request): JsonResponse
    {
        $group = $this->quotaGroupService->create($request->toDTO());

        return response()->json([
            'message' => __('messages.admin.quota_group_created'),
            'quota_group' => new QuotaGroupResource($group),
        ], 201);
    }

    /**
     * Update an existing Quota Group and sync all assigned users.
     */
    #[Put('quota-groups/{quotaGroup}', name: 'admin.quota_groups.update')]
    public function update(UpdateQuotaGroupRequest $request, QuotaGroup $quotaGroup): JsonResponse
    {
        $updated = $this->quotaGroupService->update($quotaGroup, $request->toDTO());

        return response()->json([
            'message' => __('messages.admin.quota_group_updated'),
            'quota_group' => new QuotaGroupResource($updated),
        ]);
    }

    /**
     * Delete a Quota Group (cannot delete the default group).
     */
    #[Delete('quota-groups/{quotaGroup}', name: 'admin.quota_groups.destroy')]
    public function destroy(Request $request, QuotaGroup $quotaGroup): JsonResponse
    {
        $this->quotaGroupService->delete($quotaGroup);

        return response()->json([
            'message' => __('messages.admin.quota_group_deleted'),
        ]);
    }

    /**
     * Retrieve the current system-wide default quota template.
     */
    #[Get('quota/default', name: 'admin.quota.show_default')]
    public function showDefault(Request $request): JsonResponse
    {
        return response()->json([
            'quota' => $this->quotaGroupService->getDefaultQuota(),
        ]);
    }

    /**
     * Update the system-wide default quota applied to newly registered users.
     */
    #[Put('quota/default', name: 'admin.quota.update_default')]
    public function updateDefault(UpdateDefaultQuotaRequest $request): JsonResponse
    {
        $updated = $this->quotaGroupService->updateDefaultQuota($request->toDTO());

        return response()->json([
            'message' => __('messages.admin.default_quota_updated'),
            'quota' => $updated,
        ]);
    }
}
