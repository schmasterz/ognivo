<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateIpPoolRequest;
use App\Http\Requests\Admin\UpdateIpPoolRequest;
use App\Http\Resources\IpPoolResource;
use App\Models\IpPool;
use App\Services\IpManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\RouteAttributes\Attributes\Delete;
use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Middleware;
use Spatie\RouteAttributes\Attributes\Post;
use Spatie\RouteAttributes\Attributes\Prefix;
use Spatie\RouteAttributes\Attributes\Put;

/**
 * Controller managing administrative operations on IP subnet pools.
 */
#[Prefix('admin/ip-pools')]
#[Middleware(['auth:sanctum', 'active', 'admin'])]
final class AdminIpPoolController extends Controller
{
    public function __construct(
        private readonly IpManagementService $ipService,
    ) {}

    /**
     * List IP pools with live utilization metrics and optional host filtering.
     */
    #[Get('', name: 'admin.ip_pools.index')]
    public function index(Request $request): JsonResponse
    {
        /** @var array{host_id?: int|null} $filters */
        $filters = [
            'host_id' => $request->filled('host_id') ? $request->integer('host_id') : null,
        ];

        $perPage = max(1, min(100, $request->integer('per_page', 15)));
        $paginator = $this->ipService->paginatePoolsWithStats($filters, $perPage);

        return response()->json([
            'data' => IpPoolResource::collection($paginator->items()),
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
     * Create a new routed IP subnet pool.
     */
    #[Post('', name: 'admin.ip_pools.store')]
    public function store(CreateIpPoolRequest $request): JsonResponse
    {
        $pool = $this->ipService->createPool($request->toDTO());

        return response()->json([
            'message' => 'IP pool created successfully.',
            'pool' => new IpPoolResource($pool),
        ], 201);
    }

    /**
     * Delete an IP pool if it contains no active IP assignments.
     */
    #[Delete('{ipPool}', name: 'admin.ip_pools.destroy')]
    public function destroy(Request $request, IpPool $ipPool): JsonResponse
    {
        $this->ipService->deletePool($ipPool);

        return response()->json([
            'message' => __('messages.admin.pool_deleted'),
        ]);
    }

    /**
     * Update an IP pool configuration.
     */
    #[Put('{ipPool}', name: 'admin.ip_pools.update')]
    public function update(UpdateIpPoolRequest $request, IpPool $ipPool): JsonResponse
    {
        $updatedPool = $this->ipService->updatePool($ipPool, $request->validated());

        return response()->json([
            'message' => 'IP pool updated successfully.',
            'pool' => new IpPoolResource($updatedPool),
        ]);
    }

    /**
     * Run preflight check on an IP pool.
     */
    #[Post('{ipPool}/preflight', name: 'admin.ip_pools.preflight')]
    public function preflight(Request $request, IpPool $ipPool): JsonResponse
    {
        $result = $this->ipService->preflightPool($ipPool);

        return response()->json($result);
    }
}
