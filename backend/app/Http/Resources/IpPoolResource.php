<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\IpPool;
use App\Models\User;
use App\Services\IpManagementService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/**
 * API Resource transforming IpPool entity.
 *
 * @property-read IpPool $resource
 *
 * @mixin IpPool
 */
final class IpPoolResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        $pool = $this->resource;
        /** @var User|null $user */
        $user = $request->user();
        $isAdmin = $user !== null && $user->hasRole('admin');

        if (! $isAdmin) {
            return (new TenantIpPoolResource($pool))->toArray($request);
        }

        $utilization = $pool->utilization ?? app(IpManagementService::class)->getPoolUtilization($pool);

        return [
            'id' => $pool->id,
            'host_id' => $pool->host_id,
            'name' => $pool->name,
            'cidr' => $pool->cidr ?? $pool->subnet,
            'subnet' => $pool->subnet ?? $pool->cidr,
            'first_usable' => $pool->first_usable,
            'last_usable' => $pool->last_usable,
            'parent_interface' => $pool->parent_interface !== '' ? $pool->parent_interface : null,
            'network_mode' => $pool->network_mode ?? 'routed',
            'gateway' => $pool->gateway,
            'dns_servers' => $pool->dns_servers,
            'ip_version' => $pool->ip_version,
            'cooldown_seconds' => $pool->cooldown_seconds ?? 300,
            'is_public' => (bool) $pool->is_public,
            'active' => (bool) $pool->active,
            'vlan_id' => $pool->vlan_id,
            'description' => $pool->description,
            'utilization' => $utilization,
            'total_ips' => $utilization['total'],
            'free_ips' => $utilization['free'],
            'allocated_ips' => $utilization['allocated'],
            'cooling_ips' => $utilization['cooling'],
            'reserved_ips' => $utilization['cooling'],
            'created_at' => $pool->created_at?->toISOString(),
            'updated_at' => $pool->updated_at?->toISOString(),
            'host' => $this->whenLoaded('host', fn (): ?JsonResource => $pool->host !== null ? ($isAdmin ? new HostNodeResource($pool->host) : new TenantHostResource($pool->host)) : null),
            'allocations' => $this->whenLoaded('allocations', fn (): AnonymousResourceCollection => IpAllocationResource::collection($pool->allocations)),
            'allocations_count' => $this->whenCounted('allocations'),
        ];
    }
}
