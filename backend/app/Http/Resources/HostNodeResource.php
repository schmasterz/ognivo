<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Host;
use App\Services\HostCapacityGuard;
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

        $guard = app(HostCapacityGuard::class);
        $committed = ['cpu' => 0, 'ram' => 0, 'disk' => 0];

        if ($host->relationLoaded('virtualMachines')) {
            $activeVms = $host->virtualMachines->whereNotIn('status', ['failed', 'deleted']);
            $committed = [
                'cpu' => (int) $activeVms->sum('cpu_cores'),
                'ram' => (int) $activeVms->sum('ram_mb'),
                'disk' => (int) $activeVms->sum('disk_gb'),
            ];
        } elseif (! $request->routeIs('admin.ip_pools.*', 'admin.ip-pools.*', 'ip_pools.*', 'ip-pools.*', 'admin.images.*', 'images.*')) {
            $committed = $guard->getCommittedUsage($host);
        }

        $max = $guard->getMaxAllocatable($host);

        $freeCpu = max(0, (int) floor($max['cpu'] - $committed['cpu']));
        $freeRam = max(0, (int) floor($max['ram'] - $committed['ram']));
        $freeDisk = max(0, (int) floor($max['disk'] - $committed['disk']));

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
            'capacity_source' => $host->capacity_source ?? 'unknown',
            'capacity_synced_at' => $host->capacity_synced_at?->toISOString(),
            'capacity' => [
                'source' => $host->capacity_source ?? 'unknown',
                'synced_at' => $host->capacity_synced_at?->toISOString(),
                'cpu' => [
                    'total' => $host->total_cpu_cores,
                    'committed' => $committed['cpu'],
                    'free' => $freeCpu,
                ],
                'ram' => [
                    'total' => $host->total_ram_mb,
                    'committed' => $committed['ram'],
                    'free' => $freeRam,
                ],
                'disk' => [
                    'total' => $host->total_storage_gb,
                    'committed' => $committed['disk'],
                    'free' => $freeDisk,
                ],
            ],
            'settings' => $host->settings,
            'virtual_machines_count' => $this->whenCounted('virtualMachines'),
            'ip_pools_count' => $this->whenCounted('ipPools'),
            'created_at' => $host->created_at?->toISOString(),
            'updated_at' => $host->updated_at?->toISOString(),
        ];
    }
}
