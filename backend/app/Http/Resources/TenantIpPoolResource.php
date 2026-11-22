<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\IpPool;
use App\Services\IpManagementService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/**
 * API Resource transforming IpPool entity for tenant visibility.
 * Returns only safe identifiers and IP availability status.
 *
 * @property-read IpPool $resource
 *
 * @mixin IpPool
 */
final class TenantIpPoolResource extends JsonResource
{
    /**
     * @return array{id: int, name: string, host_id: int|null, free: bool}
     */
    #[Override]
    public function toArray(Request $request): array
    {
        $pool = $this->resource;
        $utilization = $pool->utilization ?? app(IpManagementService::class)->getPoolUtilization($pool);
        $hasFree = $utilization['free'] > 0;

        return [
            'id' => $pool->id,
            'name' => $pool->name,
            'host_id' => $pool->host_id,
            'free' => $hasFree,
        ];
    }
}
