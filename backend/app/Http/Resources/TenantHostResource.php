<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\DTO\Cast;
use App\Models\Host;
use App\Services\HostCapacityGuard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/**
 * API Resource transforming Host cluster node entity for tenant visibility.
 *
 * @property-read Host $resource
 *
 * @mixin Host
 */
final class TenantHostResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        $host = $this->resource;

        $guard = app(HostCapacityGuard::class);
        $committed = ['cpu' => 0, 'ram' => 0, 'disk' => 0];

        /** @var mixed $precomputed */
        $precomputed = $host->getAttribute('precomputed_committed_usage');

        if (is_array($precomputed) && isset($precomputed['cpu'], $precomputed['ram'], $precomputed['disk'])) {
            $committed = [
                'cpu' => Cast::int($precomputed['cpu']),
                'ram' => Cast::int($precomputed['ram']),
                'disk' => Cast::int($precomputed['disk']),
            ];
        } elseif ($host->relationLoaded('virtualMachines')) {
            $activeVms = $host->virtualMachines->whereNotIn('status', ['failed', 'deleted']);
            $committed = [
                'cpu' => Cast::int($activeVms->sum('cpu_cores')),
                'ram' => Cast::int($activeVms->sum('ram_mb')),
                'disk' => Cast::int($activeVms->sum('disk_gb')),
            ];
        } elseif ($request->routeIs('hosts.active')) {
            $committed = $guard->getCommittedUsage($host);
        }

        $maxInstance = $guard->getMaxAllocatableForSingleInstance($host, $committed);

        return [
            'id' => $host->id,
            'name' => $host->name,
            'region' => $host->region,
            'driver' => $host->driver,
            'status' => $host->status,
            'is_active' => (bool) $host->is_active,
            'max_instance' => [
                'cpu_cores' => $maxInstance['cpu'],
                'ram_mb' => $maxInstance['ram'],
                'disk_gb' => $maxInstance['disk'],
            ],
        ];
    }
}
