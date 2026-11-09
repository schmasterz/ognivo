<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\IpAllocationResource;
use App\Http\Resources\IpPoolResource;
use App\Http\Resources\TenantIpPoolResource;
use App\Models\IpAllocation;
use App\Models\User;
use App\Services\IpManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\RouteAttributes\Attributes\Delete;
use Spatie\RouteAttributes\Attributes\Get;

/**
 * Controller handling IP allocations and pool queries.
 * Note: Floating IP, PTR, and Reassign routes have been retired in v1.0.
 */
final class IpAllocationController extends Controller
{
    public function __construct(
        private readonly IpManagementService $ipService,
    ) {}

    /**
     * List IP allocations accessible by the user with optional filters.
     */
    #[Get('ips', name: 'ips.index', middleware: ['auth:sanctum', 'active'])]
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{user_id?: int|null, host_id?: int|null, pool_id?: int|null, status?: string|null, search?: string|null} $filters */
        $filters = [
            'user_id' => $request->filled('user_id') ? $request->integer('user_id') : null,
            'host_id' => $request->filled('host_id') ? $request->integer('host_id') : null,
            'pool_id' => $request->filled('pool_id') ? $request->integer('pool_id') : null,
            'status' => $request->filled('status') ? $request->string('status')->value() : null,
            'search' => $request->filled('search') ? $request->string('search')->value() : null,
        ];

        $perPage = max(1, min(100, $request->integer('per_page', 10)));
        $paginator = $this->ipService->paginateForUser($user, $filters, $perPage);

        return response()->json([
            'data' => IpAllocationResource::collection($paginator->items()),
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
     * Emergency admin release of an IP allocation.
     */
    #[Delete('admin/ips/{ipAllocation}/release', name: 'admin.ips.release', middleware: ['auth:sanctum', 'active', 'admin'])]
    public function emergencyRelease(Request $request, IpAllocation $ipAllocation): JsonResponse
    {
        $forceDelete = $request->boolean('force_delete', false);
        $this->ipService->emergencyReleaseAllocation($ipAllocation, $forceDelete);

        return response()->json([
            'message' => 'IP allocation released successfully.',
        ]);
    }

    /**
     * List all IP pools with capacity stats and optional node filtering.
     */
    #[Get('ip-pools', name: 'ip_pools.list', middleware: ['auth:sanctum', 'active'])]
    public function pools(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        $isAdmin = $user !== null && $user->hasRole('admin');

        /** @var array{host_id?: int|null} $filters */
        $filters = [
            'host_id' => $request->filled('host_id') ? $request->integer('host_id') : null,
        ];

        $perPage = max(1, min(100, $request->integer('per_page', 10)));
        $paginator = $this->ipService->paginatePoolsWithStats($filters, $perPage);

        $resourceClass = $isAdmin ? IpPoolResource::class : TenantIpPoolResource::class;

        return response()->json([
            'data' => $resourceClass::collection($paginator->items()),
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
}
