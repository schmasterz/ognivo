<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\VpsPlanCatalogResource;
use App\Services\Vps\VpsPlanCatalogService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Middleware;
use Spatie\RouteAttributes\Attributes\Prefix;

/**
 * Controller providing the public VPS plan catalog to authenticated users.
 */
#[Group('VPS Plans')]
#[Prefix('vps/plans')]
#[Middleware(['auth:sanctum', 'active'])]
final class VpsPlanCatalogController extends Controller
{
    public function __construct(
        private readonly VpsPlanCatalogService $catalogService,
    ) {}

    /**
     * List all published VPS plans available for provisioning.
     */
    #[Get('', name: 'vps.plans.index')]
    public function index(): AnonymousResourceCollection
    {
        return VpsPlanCatalogResource::collection(
            $this->catalogService->listPublishedPlans()
        );
    }

    /**
     * Show a single published VPS plan by slug.
     */
    #[Get('{slug}', name: 'vps.plans.show')]
    public function show(string $slug): VpsPlanCatalogResource
    {
        return new VpsPlanCatalogResource(
            $this->catalogService->getPublishedPlanBySlug($slug)
        );
    }
}
