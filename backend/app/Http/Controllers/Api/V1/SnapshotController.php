<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\VmSnapshotResource;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Models\VmSnapshot;
use App\Services\UserQuotaService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Middleware;
use Spatie\RouteAttributes\Attributes\Prefix;

/**
 * Controller managing platform-wide snapshot queries and aggregations.
 */
#[Group('Snapshots')]
#[Prefix('snapshots')]
#[Middleware(['auth:sanctum', 'active'])]
final class SnapshotController extends Controller
{
    public function __construct(
        private readonly UserQuotaService $quotaService,
    ) {}

    /**
     * List snapshots across virtual machines for the authenticated user or all workloads if admin.
     */
    #[Get('', name: 'snapshots.index')]
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Builder<VmSnapshot> $query */
        $query = VmSnapshot::with([
            'virtualMachine:id,name,incus_project,status,user_id',
            'user:id,name,email',
        ]);

        if (! $user->hasRole('admin')) {
            $query->where('user_id', $user->id);
        } else {
            if ($request->filled('user_id')) {
                $query->where('user_id', $request->integer('user_id'));
            }
        }

        if ($request->filled('vm_id')) {
            $query->where('virtual_machine_id', $request->integer('vm_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->value());
        }

        if ($request->filled('source')) {
            $query->where('source', $request->string('source')->value());
        }

        if ($request->filled('search')) {
            $search = $request->string('search')->value();
            $query->where(function (Builder $q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhereHas('virtualMachine', function (Builder $vq) use ($search): void {
                        $vq->where('name', 'like', "%{$search}%");
                    });
            });
        }

        $perPage = min(max($request->integer('per_page', 15), 1), 100);
        $paginator = $query->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        $targetUser = ($user->hasRole('admin') && $request->filled('user_id'))
            ? (User::find($request->integer('user_id')) ?? $user)
            : $user;
        $targetVm = $request->filled('vm_id')
            ? VirtualMachine::find($request->integer('vm_id'))
            : null;

        $quota = $this->quotaService->resolveUserQuota($targetUser);
        $used = $this->quotaService->countActiveSnapshots($targetUser, $targetVm);
        $remaining = $this->quotaService->remainingSnapshots($targetUser, $targetVm);
        $maxSnapshots = $quota->max_snapshots ?? -1;

        return response()->json([
            'data' => VmSnapshotResource::collection($paginator->items())->resolve($request),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'meta' => [
                'used' => $used,
                'max_snapshots' => $maxSnapshots,
                'remaining' => $remaining,
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
