<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vps\StoreVpsPlanRequest;
use App\Http\Requests\Vps\UpdateVpsPlanRequest;
use App\Http\Requests\Vps\UpsertVpsPlanDraftVersionRequest;
use App\Http\Resources\AdminVpsPlanResource;
use App\Http\Resources\AdminVpsPlanVersionResource;
use App\Models\User;
use App\Models\VpsPlan;
use App\Services\Vps\VpsPlanCatalogService;
use App\Services\Vps\VpsPlanService;
use App\Services\Vps\VpsPlanVersionService;
use App\States\VpsPlan\VpsPlanState;
use Closure;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Spatie\ModelStates\Exceptions\CouldNotPerformTransition;
use Spatie\RouteAttributes\Attributes\Delete;
use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Middleware;
use Spatie\RouteAttributes\Attributes\Patch;
use Spatie\RouteAttributes\Attributes\Post;
use Spatie\RouteAttributes\Attributes\Prefix;
use Spatie\RouteAttributes\Attributes\Put;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Controller managing administrative operations on VPS catalog plans and their versions.
 */
#[Group('Admin VPS Plans')]
#[Prefix('admin/vps/plans')]
#[Middleware(['auth:sanctum', 'active', 'can:vps.plans.manage'])]
final class AdminVpsPlanController extends Controller
{
    public function __construct(
        private readonly VpsPlanCatalogService $catalogService,
        private readonly VpsPlanService $planService,
        private readonly VpsPlanVersionService $versionService,
    ) {}

    /**
     * List all VPS plans with optional status and search filtering.
     */
    #[Get('', name: 'admin.vps.plans.index')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', 'string', Rule::in(['all', ...VpsPlanState::values()])],
            'search' => ['nullable', 'string', 'max:140'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $filters = [
            'status' => $request->filled('status') ? $request->string('status')->value() : null,
            'search' => $request->filled('search') ? $request->string('search')->value() : null,
            'per_page' => $request->integer('per_page', 20),
        ];

        return AdminVpsPlanResource::collection(
            $this->catalogService->listAdminPlans($filters)
        );
    }

    /**
     * Create a new VPS plan in draft status with an initial draft version.
     */
    #[Post('', name: 'admin.vps.plans.store')]
    public function store(StoreVpsPlanRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $plan = $this->planService->create($request->validated(), $actor);

        return (new AdminVpsPlanResource($plan))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Show administrative details for a VPS plan.
     */
    #[Get('{plan}', name: 'admin.vps.plans.show')]
    public function show(VpsPlan $plan): AdminVpsPlanResource
    {
        $plan->load(['currentVersion', 'draftVersion']);

        return new AdminVpsPlanResource($plan);
    }

    /**
     * Update VPS plan metadata without bumping the plan version.
     */
    #[Patch('{plan}', name: 'admin.vps.plans.update')]
    public function update(UpdateVpsPlanRequest $request, VpsPlan $plan): AdminVpsPlanResource
    {
        /** @var User $actor */
        $actor = $request->user();
        $updated = $this->planService->updateMetadata($plan, $request->validated(), $actor);

        return new AdminVpsPlanResource($updated);
    }

    /**
     * Publish a draft VPS plan after validating publish eligibility.
     */
    #[Post('{plan}/publish', name: 'admin.vps.plans.publish')]
    public function publish(Request $request, VpsPlan $plan): AdminVpsPlanResource
    {
        /** @var User $actor */
        $actor = $request->user();
        $published = $this->runStateTransition(
            fn (): VpsPlan => $this->planService->publish($plan, $actor)
        );

        return new AdminVpsPlanResource($published);
    }

    /**
     * Unpublish a published VPS plan back to draft status.
     */
    #[Post('{plan}/unpublish', name: 'admin.vps.plans.unpublish')]
    public function unpublish(Request $request, VpsPlan $plan): AdminVpsPlanResource
    {
        /** @var User $actor */
        $actor = $request->user();
        $unpublished = $this->runStateTransition(
            fn (): VpsPlan => $this->planService->unpublish($plan, $actor)
        );

        return new AdminVpsPlanResource($unpublished);
    }

    /**
     * Archive a draft or published VPS plan.
     */
    #[Post('{plan}/archive', name: 'admin.vps.plans.archive')]
    public function archive(Request $request, VpsPlan $plan): AdminVpsPlanResource
    {
        /** @var User $actor */
        $actor = $request->user();
        $archived = $this->runStateTransition(
            fn (): VpsPlan => $this->planService->archive($plan, $actor)
        );

        return new AdminVpsPlanResource($archived);
    }

    /**
     * Restore an archived VPS plan to draft status.
     */
    #[Post('{plan}/unarchive', name: 'admin.vps.plans.unarchive')]
    public function unarchive(Request $request, VpsPlan $plan): AdminVpsPlanResource
    {
        /** @var User $actor */
        $actor = $request->user();
        $unarchived = $this->runStateTransition(
            fn (): VpsPlan => $this->planService->unarchive($plan, $actor)
        );

        return new AdminVpsPlanResource($unarchived);
    }

    /**
     * List all versions belonging to a VPS plan.
     */
    #[Get('{plan}/versions', name: 'admin.vps.plans.versions.index')]
    public function versions(VpsPlan $plan): AnonymousResourceCollection
    {
        return AdminVpsPlanVersionResource::collection(
            $this->catalogService->listPlanVersions($plan)
        );
    }

    /**
     * Create or replace the active draft version profile for a VPS plan.
     */
    #[Put('{plan}/versions/draft', name: 'admin.vps.plans.versions.draft.upsert')]
    public function upsertDraft(UpsertVpsPlanDraftVersionRequest $request, VpsPlan $plan): AdminVpsPlanVersionResource
    {
        /** @var User $actor */
        $actor = $request->user();
        $draft = $this->versionService->upsertDraft($plan, $request->validated(), $actor);

        return new AdminVpsPlanVersionResource($draft);
    }

    /**
     * Discard the active draft version of a VPS plan.
     */
    #[Delete('{plan}/versions/draft', name: 'admin.vps.plans.versions.draft.destroy')]
    public function destroyDraft(Request $request, VpsPlan $plan): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->versionService->discardDraft($plan, $actor);

        return response()->json([
            'data' => [
                'discarded' => true,
            ],
        ]);
    }

    /**
     * Publish the plan's draft version, superseding any previously published version.
     */
    #[Post('{plan}/versions/draft/publish', name: 'admin.vps.plans.versions.draft.publish')]
    public function publishDraft(Request $request, VpsPlan $plan): AdminVpsPlanVersionResource
    {
        /** @var User $actor */
        $actor = $request->user();
        $publishedVersion = $this->runStateTransition(
            fn () => $this->versionService->publishDraft($plan, $actor)
        );

        return new AdminVpsPlanVersionResource($publishedVersion);
    }

    /**
     * Return active (non-deleted) VM usage counts per version for a VPS plan.
     */
    #[Get('{plan}/usage', name: 'admin.vps.plans.usage')]
    public function usage(VpsPlan $plan): JsonResponse
    {
        return response()->json([
            'data' => $this->catalogService->getPlanUsage($plan),
        ]);
    }

    /**
     * Execute a state transition callback and normalize state-machine transition errors to HTTP 409.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    private function runStateTransition(Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (CouldNotPerformTransition $e) {
            throw new ConflictHttpException($e->getMessage(), $e);
        }
    }
}
