<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Host;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/**
 * API Resource transforming Host cluster node entity.
 *
 * @property-read Host $resource
 *
 * @mixin Host
 */
final class HostNodeResource extends JsonResource
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
            'endpoint_url' => $host->endpoint_url,
            'driver' => $host->driver,
            'server_fingerprint' => $host->server_fingerprint,
            'server_public_key_pin' => $host->server_public_key_pin,
            'status' => $host->status,
            'needs_retrust' => (bool) $host->needs_retrust,
            'last_checked_at' => $host->last_checked_at?->toISOString(),
            'has_token' => $host->token !== null && $host->token !== '',
            'is_active' => (bool) $host->is_active,
            'total_cpu_cores' => $host->total_cpu_cores,
            'total_ram_mb' => $host->total_ram_mb,
            'total_storage_gb' => $host->total_storage_gb,
            'settings' => $host->settings,
            'virtual_machines_count' => $this->whenCounted('virtualMachines'),
            'ip_pools_count' => $this->whenCounted('ipPools'),
            'created_at' => $host->created_at?->toISOString(),
            'updated_at' => $host->updated_at?->toISOString(),
        ];
    }
}
