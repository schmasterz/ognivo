<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vps\PlanChangePreviewRequest;
use App\Http\Requests\Vps\PlanChangeStoreRequest;
use App\Http\Resources\VpsPlanChangePreviewResource;
use App\Http\Resources\VpsPlanChangeResource;
use App\Http\Resources\VpsPlanChangeTargetResource;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Models\VpsPlan;
use App\Models\VpsPlanChange;
use App\Models\VpsPlanVersion;
use App\Services\Vps\VpsPlanCatalogService;
use App\Services\Vps\VpsPlanChangeService;
use App\Services\Vps\VpsPlanChangeValidator;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\ModelStates\Exceptions\CouldNotPerformTransition;
use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Middleware;
use Spatie\RouteAttributes\Attributes\Post;
use Spatie\RouteAttributes\Attributes\Prefix;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Controller managing customer and administrator VPS plan-change operations on a virtual machine.
 */
#[Group('Virtual Machine Plan Changes')]
#[Prefix('vms')]
#[Middleware(['auth:sanctum', 'active'])]
final class VmPlanChangeController extends Controller
{
    public function __construct(
        private readonly VpsPlanCatalogService $catalogService,
        private readonly VpsPlanChangeValidator $validator,
        private readonly VpsPlanChangeService $planChangeService,
    ) {}

    /**
     * List published VPS plans evaluated for eligibility against the target virtual machine.
     */
    #[Get('{vm}/plan-change/targets', name: 'vms.plan-change.targets', middleware: ['throttle:plan-change-preview'])]
    #[PathParameter('vm', description: 'The ID of the virtual machine.', type: 'integer')]
    public function targets(Request $request, VirtualMachine $vm): AnonymousResourceCollection
    {
        $user = $this->authorizeVmAccess($request, $vm, 'changePlan', requireRequestGate: true);
        $isAdmin = $user->hasRole('admin');

        $vm->loadMissing(['host', 'primaryAllocation.pool', 'vpsPlanVersion.vpsPlan']);

        $plans = $this->catalogService->listPublishedPlans();

        foreach ($plans as $plan) {
            $version = $plan->currentVersion;
            if ($version === null) {
                continue;
            }

            $version->setRelation('vpsPlan', $plan);
            $validation = $this->validator->validate($vm, $version, $isAdmin);
            $plan->setAttribute('plan_change_validation', $validation);
        }

        return VpsPlanChangeTargetResource::collection($plans);
    }

    /**
     * Preview a VPS plan change against the virtual machine without mutating state.
     */
    #[Post('{vm}/plan-change/preview', name: 'vms.plan-change.preview', middleware: ['throttle:plan-change-preview'])]
    #[PathParameter('vm', description: 'The ID of the virtual machine.', type: 'integer')]
    public function preview(PlanChangePreviewRequest $request, VirtualMachine $vm): VpsPlanChangePreviewResource
    {
        $user = $this->authorizeVmAccess($request, $vm, 'changePlan', requireRequestGate: true);
        $isAdmin = $user->hasRole('admin');

        $vm->loadMissing(['host', 'primaryAllocation.pool', 'vpsPlanVersion.vpsPlan']);
        $targetVersion = $this->resolveTargetVersion($request, $isAdmin);
        $validation = $this->validator->validate($vm, $targetVersion, $isAdmin);

        $vm->setAttribute('plan_change_target_version', $targetVersion);
        $vm->setAttribute('plan_change_validation', $validation);

        return new VpsPlanChangePreviewResource($vm);
    }

    /**
     * Submit an asynchronous VPS plan-change request for the virtual machine.
     */
    #[Post('{vm}/plan-change', name: 'vms.plan-change.store', middleware: ['throttle:vm-actions', 'idempotency'])]
    #[PathParameter('vm', description: 'The ID of the virtual machine.', type: 'integer')]
    public function store(PlanChangeStoreRequest $request, VirtualMachine $vm): JsonResponse
    {
        $user = $this->authorizeVmAccess($request, $vm, 'changePlan', requireRequestGate: true);
        $isAdmin = $user->hasRole('admin');

        $vm->loadMissing(['host', 'primaryAllocation.pool', 'vpsPlanVersion.vpsPlan']);
        $targetVersion = $this->resolveTargetVersion($request, $isAdmin);

        try {
            $planChange = $this->planChangeService->request($vm, $targetVersion, $user, $isAdmin);
        } catch (ValidationException $e) {
            $response = $e->response;
            if ($response instanceof JsonResponse) {
                /** @var array<string, mixed> $payload */
                $payload = $response->getData(true);
                $status = $response->getStatusCode();

                if ($status !== 409 && $this->hasServiceUnavailableReason($payload)) {
                    return response()->json($payload, 503);
                }

                return response()->json($payload, $status);
            }

            throw $e;
        }

        $planChange->load(['fromVersion.vpsPlan', 'toVersion.vpsPlan']);

        return (new VpsPlanChangeResource($planChange))
            ->response()
            ->setStatusCode(202);
    }

    /**
     * List paginated plan-change history for a virtual machine.
     */
    #[Get('{vm}/plan-changes', name: 'vms.plan-changes.index')]
    #[PathParameter('vm', description: 'The ID of the virtual machine.', type: 'integer')]
    public function index(Request $request, VirtualMachine $vm): AnonymousResourceCollection
    {
        $this->authorizeVmAccess($request, $vm, 'view');

        $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = max(1, min(100, $request->integer('per_page', 15)));

        $changes = VpsPlanChange::query()
            ->with(['fromVersion.vpsPlan', 'toVersion.vpsPlan'])
            ->where('virtual_machine_id', $vm->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return VpsPlanChangeResource::collection($changes);
    }

    /**
     * Retrieve details for a single plan-change operation belonging to the virtual machine.
     */
    #[Get('{vm}/plan-changes/{public_id}', name: 'vms.plan-changes.show')]
    #[PathParameter('vm', description: 'The ID of the virtual machine.', type: 'integer')]
    #[PathParameter('public_id', description: 'The public ULID identifier of the plan change.', type: 'string')]
    public function show(Request $request, VirtualMachine $vm, string $public_id): VpsPlanChangeResource
    {
        $this->authorizeVmAccess($request, $vm, 'view');

        $planChange = $this->findPlanChangeForVm($vm, $public_id);

        return new VpsPlanChangeResource($planChange);
    }

    /**
     * Cancel a queued or awaiting-authorization plan-change operation for the virtual machine.
     */
    #[Post('{vm}/plan-changes/{public_id}/cancel', name: 'vms.plan-changes.cancel', middleware: ['throttle:vm-actions'])]
    #[PathParameter('vm', description: 'The ID of the virtual machine.', type: 'integer')]
    #[PathParameter('public_id', description: 'The public ULID identifier of the plan change.', type: 'string')]
    public function cancel(Request $request, VirtualMachine $vm, string $public_id): VpsPlanChangeResource|JsonResponse
    {
        $user = $this->authorizeVmAccess($request, $vm, 'changePlan');

        $planChange = $this->findPlanChangeForVm($vm, $public_id);

        try {
            $cancelled = $this->planChangeService->cancel($planChange, $user);
        } catch (CouldNotPerformTransition|ConflictHttpException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'PLAN_CHANGE_CANNOT_BE_CANCELLED',
            ], 409);
        }

        $cancelled->load(['fromVersion.vpsPlan', 'toVersion.vpsPlan']);

        return new VpsPlanChangeResource($cancelled);
    }

    /**
     * Reject service tokens, hide non-owned VMs with 404, and authorize policy/capability gates.
     */
    private function authorizeVmAccess(
        Request $request,
        VirtualMachine $vm,
        string $ability,
        bool $requireRequestGate = false,
    ): User {
        if ($request->attributes->get('service_token') !== null || str_starts_with($request->bearerToken() ?? '', 'srv_')) {
            abort(403, 'Service tokens are not permitted to perform plan-change actions.');
        }

        /** @var User|null $user */
        $user = $request->user();
        if ($user === null) {
            abort(401, 'Unauthenticated.');
        }

        if (! $user->hasRole('admin') && $vm->user_id !== $user->id) {
            abort(404);
        }

        Gate::authorize($ability, $vm);

        if ($requireRequestGate) {
            Gate::authorize('vps.plan_change.request');
        }

        return $user;
    }

    /**
     * Resolve the target VpsPlanVersion from `vps_plan_version_id` (admin) or `vps_plan_id` current version.
     *
     * @throws ValidationException
     */
    private function resolveTargetVersion(Request $request, bool $isAdmin): VpsPlanVersion
    {
        if ($isAdmin && $request->filled('vps_plan_version_id')) {
            /** @var VpsPlanVersion $version */
            $version = VpsPlanVersion::query()
                ->with('vpsPlan')
                ->findOrFail($request->integer('vps_plan_version_id'));

            return $version;
        }

        /** @var VpsPlan $plan */
        $plan = VpsPlan::query()
            ->with('currentVersion')
            ->findOrFail($request->integer('vps_plan_id'));

        $currentVersion = $plan->currentVersion;
        if ($currentVersion === null) {
            $reason = [
                'code' => 'PLAN_NOT_AVAILABLE',
                'message' => 'The selected VPS plan does not have a published version available.',
            ];

            $exception = ValidationException::withMessages([
                'vps_plan_id' => [$reason['message']],
            ]);
            $exception->status(422);
            $exception->response = response()->json([
                'message' => $reason['message'],
                'code' => $reason['code'],
                'blocking_reasons' => [$reason],
                'errors' => $exception->errors(),
            ], 422);

            throw $exception;
        }

        $currentVersion->setRelation('vpsPlan', $plan);

        return $currentVersion;
    }

    /**
     * Locate a plan-change record by public ULID and verify it belongs to the specified VM.
     */
    private function findPlanChangeForVm(VirtualMachine $vm, string $publicId): VpsPlanChange
    {
        /** @var VpsPlanChange|null $planChange */
        $planChange = VpsPlanChange::query()
            ->with(['fromVersion.vpsPlan', 'toVersion.vpsPlan'])
            ->where('public_id', $publicId)
            ->first();

        if ($planChange === null || $planChange->virtual_machine_id !== $vm->id) {
            abort(404);
        }

        return $planChange;
    }

    /**
     * Determine whether any blocking reason indicates a temporary infrastructure or extension outage (HTTP 503).
     *
     * @param  array<string, mixed>  $payload
     */
    private function hasServiceUnavailableReason(array $payload): bool
    {
        $reasons = $payload['blocking_reasons'] ?? null;
        if (! is_array($reasons)) {
            return false;
        }

        foreach ($reasons as $reason) {
            if (! is_array($reason)) {
                continue;
            }

            $code = $reason['code'] ?? null;
            if ($code === 'HOST_UNREACHABLE' || $code === 'EXTENSION_AUTHORIZATION_UNAVAILABLE') {
                return true;
            }
        }

        return false;
    }
}
