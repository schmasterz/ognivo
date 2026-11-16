<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Host;
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

        return [
            'id' => $host->id,
            'name' => $host->name,
            'region' => $host->region,
            'driver' => $host->driver,
            'status' => $host->status,
            'is_active' => (bool) $host->is_active,
        ];
    }
}
