<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\DuplicateSnapshotNameException;
use App\Exceptions\InvalidSnapshotNameException;
use App\Exceptions\InvalidSnapshotStateException;
use App\Exceptions\InvalidVmStateException;
use App\Exceptions\SnapshotQuotaExceededException;
use App\Http\Requests\Vm\CreateSnapshotRequest;
use App\Http\Requests\Vm\DeleteSnapshotRequest;
use App\Http\Requests\Vm\RestoreSnapshotRequest;
use App\Http\Resources\VmSnapshotResource;
use App\Models\VirtualMachine;
use App\Models\VmSnapshot;
use App\Services\UserQuotaService;
use App\Services\VmSnapshotService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\RouteAttributes\Attributes\Delete;
use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Middleware;
use Spatie\RouteAttributes\Attributes\Post;
use Spatie\RouteAttributes\Attributes\Prefix;

/**
 * Controller managing manual Virtual Machine and Container snapshot operations.
 */
#[Group('Virtual Machine Snapshots')]
#[Prefix('vms')]
#[Middleware(['auth:sanctum', 'active'])]
final class VmSnapshotController extends Controller
{
    public function __construct(
        private readonly VmSnapshotService $snapshotService,
        private readonly UserQuotaService $quotaService,
    ) {}

    /**
     * List all snapshots for a specific virtual machine.
     */
    #[Get('{vm}/snapshots', name: 'vms.snapshots.index')]
    #[PathParameter('vm', description: 'The ID of the virtual machine.', type: 'integer')]
    public function index(Request $request, VirtualMachine $vm): AnonymousResourceCollection
    {
        Gate::authorize('view', $vm);

        $snapshots = $vm->snapshots()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $owner = $vm->user ?? $request->user();
        $quota = $owner !== null ? $this->quotaService->resolveUserQuota($owner) : null;
        $used = $owner !== null ? $this->quotaService->countActiveSnapshots($owner, $vm) : 0;
        $remaining = $owner !== null ? $this->quotaService->remainingSnapshots($owner, $vm) : -1;
        $maxSnapshots = $quota !== null ? ($quota->max_snapshots ?? -1) : -1;

        return VmSnapshotResource::collection($snapshots)->additional([
            'meta' => [
                'used' => $used,
                'max_snapshots' => $maxSnapshots,
                'remaining' => $remaining,
            ],
        ]);
    }

    /**
     * Create a new manual snapshot for a virtual machine asynchronously.
     */
    #[Post('{vm}/snapshots', name: 'vms.snapshots.store', middleware: ['throttle:vm-actions'])]
    #[PathParameter('vm', description: 'The ID of the virtual machine.', type: 'integer')]
    public function store(CreateSnapshotRequest $request, VirtualMachine $vm): JsonResponse
    {
        Gate::authorize('executeAction', $vm);

        $dto = $request->toDTO();

        try {
            $snapshot = $this->snapshotService->create($vm, $dto->name, $dto->stateful);
        } catch (SnapshotQuotaExceededException $e) {
            throw ValidationException::withMessages([
                'quota' => [$e->getMessage()],
            ]);
        } catch (DuplicateSnapshotNameException|InvalidSnapshotNameException $e) {
            throw ValidationException::withMessages([
                'name' => [$e->getMessage()],
            ]);
        } catch (InvalidVmStateException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 409);
        }

        $resource = new VmSnapshotResource($snapshot);

        return response()->json([
            'message' => __('messages.snapshot.creation_initiated'),
            'snapshot' => $resource,
            'data' => $resource,
        ], 202);
    }

    /**
     * Restore a ready snapshot on a virtual machine asynchronously.
     */
    #[Post('{vm}/snapshots/{snapshot}/restore', name: 'vms.snapshots.restore', middleware: ['throttle:vm-actions'])]
    #[PathParameter('vm', description: 'The ID of the virtual machine.', type: 'integer')]
    #[PathParameter('snapshot', description: 'The ID or slug name of the snapshot.', type: 'integer')]
    public function restore(RestoreSnapshotRequest $request, VirtualMachine $vm, int|string $snapshot): JsonResponse
    {
        Gate::authorize('executeAction', $vm);

        $targetSnapshot = $this->resolveSnapshotForVm($vm, $snapshot);

        try {
            $restoringSnapshot = $this->snapshotService->restore($vm, $targetSnapshot);
        } catch (InvalidVmStateException|InvalidSnapshotStateException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 409);
        }

        $resource = new VmSnapshotResource($restoringSnapshot);

        return response()->json([
            'message' => __('messages.snapshot.restore_initiated'),
            'snapshot' => $resource,
            'data' => $resource,
        ], 202);
    }

    /**
     * Delete a snapshot from a virtual machine asynchronously.
     */
    #[Delete('{vm}/snapshots/{snapshot}', name: 'vms.snapshots.destroy', middleware: ['throttle:vm-actions'])]
    #[PathParameter('vm', description: 'The ID of the virtual machine.', type: 'integer')]
    #[PathParameter('snapshot', description: 'The ID or slug name of the snapshot.', type: 'integer')]
    public function destroy(DeleteSnapshotRequest $request, VirtualMachine $vm, int|string $snapshot): JsonResponse
    {
        Gate::authorize('executeAction', $vm);

        $targetSnapshot = $this->resolveSnapshotForVm($vm, $snapshot);

        try {
            $deletingSnapshot = $this->snapshotService->delete($vm, $targetSnapshot);
        } catch (InvalidSnapshotStateException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 409);
        }

        $resource = new VmSnapshotResource($deletingSnapshot);

        return response()->json([
            'message' => __('messages.snapshot.deletion_initiated'),
            'snapshot' => $resource,
            'data' => $resource,
        ], 202);
    }

    /**
     * Resolve a snapshot strictly scoped to the given virtual machine, throwing 404 if not found on this VM.
     *
     * @throws ModelNotFoundException<VmSnapshot>
     */
    private function resolveSnapshotForVm(VirtualMachine $vm, int|string $snapshot): VmSnapshot
    {
        $found = null;

        if (is_int($snapshot) || ctype_digit($snapshot)) {
            /** @var VmSnapshot|null $found */
            $found = $vm->snapshots()->whereKey((int) $snapshot)->first();
        }

        if ($found === null && is_string($snapshot) && $snapshot !== '') {
            /** @var VmSnapshot|null $found */
            $found = $vm->snapshots()->where('name', $snapshot)->first();
        }

        if ($found === null) {
            throw (new ModelNotFoundException)->setModel(VmSnapshot::class, [$snapshot]);
        }

        return $found;
    }
}
