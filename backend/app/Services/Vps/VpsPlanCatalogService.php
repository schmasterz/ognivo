<?php

declare(strict_types=1);

namespace App\Services\Vps;

use App\Models\VirtualMachine;
use App\Models\VpsPlan;
use App\Models\VpsPlanVersion;
use App\States\Vm\Deleted as VmDeleted;
use App\States\VpsPlan\Published as PlanPublished;
use App\Support\Cast;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Read-side query service for public VPS plan catalog and administrative plan listings.
 */
final class VpsPlanCatalogService
{
    /**
     * List all published VPS plans that have a published current version, ordered for catalog display.
     *
     * @return Collection<int, VpsPlan>
     */
    public function listPublishedPlans(): Collection
    {
        return VpsPlan::query()
            ->with('currentVersion')
            ->where('status', PlanPublished::$name)
            ->whereNotNull('current_version_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Retrieve a single published VPS plan by slug with its current version.
     *
     * @throws ModelNotFoundException<VpsPlan>
     */
    public function getPublishedPlanBySlug(string $slug): VpsPlan
    {
        return VpsPlan::query()
            ->with('currentVersion')
            ->where('slug', $slug)
            ->where('status', PlanPublished::$name)
            ->whereNotNull('current_version_id')
            ->firstOrFail();
    }

    /**
     * List all VPS plans for administrators with optional status and search filtering.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, VpsPlan>
     */
    public function listAdminPlans(array $filters = []): LengthAwarePaginator
    {
        $query = VpsPlan::query()
            ->with(['currentVersion', 'draftVersion']);

        $status = $filters['status'] ?? null;
        if (is_string($status) && trim($status) !== '' && trim($status) !== 'all') {
            $query->where('status', trim($status));
        }

        $search = $filters['search'] ?? null;
        if (is_string($search) && trim($search) !== '') {
            $escaped = addcslashes(trim($search), '%_\\');
            $query->where(function (Builder $sub) use ($escaped): void {
                $sub->where('name', 'like', "%{$escaped}%")
                    ->orWhere('slug', 'like', "%{$escaped}%");
            });
        }

        $perPage = isset($filters['per_page']) ? Cast::int($filters['per_page'], 20) : 20;
        $perPage = max(1, min(100, $perPage));

        return $query
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate($perPage);
    }

    /**
     * List all versions for a VPS plan with non-deleted VM counts attached.
     *
     * @return Collection<int, VpsPlanVersion>
     */
    public function listPlanVersions(VpsPlan $plan): Collection
    {
        return $plan->versions()
            ->withCount([
                'virtualMachines as active_vms_count' => $this->activeVmsCountConstraint(),
            ])
            ->orderByRaw('version IS NULL DESC')
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Return per-version non-deleted VM counts for a VPS plan.
     *
     * @return array{
     *     vps_plan_id: int,
     *     total_active_vms: int,
     *     versions: list<array{
     *         vps_plan_version_id: int,
     *         version: int|null,
     *         status: string,
     *         active_vms_count: int,
     *     }>,
     * }
     */
    public function getPlanUsage(VpsPlan $plan): array
    {
        $versions = $plan->versions()
            ->withCount([
                'virtualMachines as active_vms_count' => $this->activeVmsCountConstraint(),
            ])
            ->orderByRaw('version IS NULL ASC')
            ->orderBy('version')
            ->orderBy('id')
            ->get();

        $versionItems = [];
        $totalActiveVms = 0;

        /** @var VpsPlanVersion $version */
        foreach ($versions as $version) {
            $activeCount = Cast::int($version->getAttribute('active_vms_count'));
            $totalActiveVms += $activeCount;

            $versionItems[] = [
                'vps_plan_version_id' => $version->id,
                'version' => $version->version,
                'status' => $version->status->getValue(),
                'active_vms_count' => $activeCount,
            ];
        }

        return [
            'vps_plan_id' => $plan->id,
            'total_active_vms' => $totalActiveVms,
            'versions' => $versionItems,
        ];
    }

    /**
     * Query constraint filtering out deleted virtual machines when counting version usage.
     *
     * @return Closure(Builder<Model>): Builder<VirtualMachine>
     */
    private function activeVmsCountConstraint(): Closure
    {
        return static function (Builder $query): Builder {
            /** @var Builder<VirtualMachine> $typedQuery */
            $typedQuery = $query;

            return $typedQuery->where('status', '!=', VmDeleted::$name);
        };
    }
}
