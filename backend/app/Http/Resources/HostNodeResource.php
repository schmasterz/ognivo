<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Host;
use App\Services\HostCapacityGuard;
use App\Support\Cast;
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
        } elseif (! $request->routeIs('admin.ip_pools.*', 'admin.ip-pools.*', 'ip_pools.*', 'ip-pools.*', 'admin.images.*', 'images.*')) {
            $committed = $guard->getCommittedUsage($host);
        }

        $max = $guard->getMaxAllocatable($host);
        $maxInstance = $guard->getMaxAllocatableForSingleInstance($host, $committed);

        $cpuOvercommit = Cast::float($host->cpu_overcommit, 1.0);
        $ramOvercommit = Cast::float($host->ram_overcommit, 1.0);
        $diskOvercommit = Cast::float($host->disk_overcommit, 1.0);

        $freeCpu = max(0, (int) floor((float) $max['cpu'] - (float) $committed['cpu']));
        $freeRam = max(0, (int) floor((float) $max['ram'] - (float) $committed['ram']));
        $freeDisk = max(0, (int) floor((float) $max['disk'] - (float) $committed['disk']));

        return [
            'id' => $host->id,
            'name' => $host->name,
            'region' => $host->region,
            'endpoint_url' => $host->endpoint_url,
            'driver' => $host->driver,
            'server_fingerprint' => $host->server_fingerprint,
            'server_public_key_pin' => $host->server_public_key_pin,
            'status' => $host->status->getValue(),
            'needs_retrust' => (bool) $host->needs_retrust,
            'last_checked_at' => $host->last_checked_at?->toISOString(),
            'has_token' => $host->token !== null && $host->token !== '',
            'is_active' => (bool) $host->is_active,
            'auto_attach_images' => (bool) $host->auto_attach_images,
            'total_cpu_cores' => $host->total_cpu_cores,
            'total_ram_mb' => $host->total_ram_mb,
            'total_storage_gb' => $host->total_storage_gb,
            'cpu_overcommit' => $cpuOvercommit,
            'ram_overcommit' => $ramOvercommit,
            'disk_overcommit' => $diskOvercommit,
            'capacity_source' => $host->capacity_source ?? 'unknown',
            'capacity_synced_at' => $host->capacity_synced_at?->toISOString(),
            'metrics_health' => [
                'last_success_at' => $host->metrics_last_success_at?->toISOString(),
                'last_error_at' => $host->metrics_last_error_at?->toISOString(),
                'last_error' => $host->metrics_last_error,
                'last_duration_ms' => $host->metrics_last_duration_ms,
                'consecutive_failures' => Cast::int($host->metrics_consecutive_failures),
                'backoff_until' => $host->metrics_backoff_until?->toISOString(),
            ],
            'max_instance' => [
                'cpu_cores' => $maxInstance['cpu'],
                'ram_mb' => $maxInstance['ram'],
                'disk_gb' => $maxInstance['disk'],
            ],
            'capacity' => [
                'source' => $host->capacity_source ?? 'unknown',
                'synced_at' => $host->capacity_synced_at?->toISOString(),
                'cpu' => [
                    'total' => $host->total_cpu_cores,
                    'committed' => $committed['cpu'],
                    'free' => $freeCpu,
                    'overcommit' => $cpuOvercommit,
                    'max_allocatable' => max(0, (int) floor((float) $max['cpu'])),
                ],
                'ram' => [
                    'total' => $host->total_ram_mb,
                    'committed' => $committed['ram'],
                    'free' => $freeRam,
                    'overcommit' => $ramOvercommit,
                    'max_allocatable' => max(0, (int) floor((float) $max['ram'])),
                ],
                'disk' => [
                    'total' => $host->total_storage_gb,
                    'committed' => $committed['disk'],
                    'free' => $freeDisk,
                    'overcommit' => $diskOvercommit,
                    'max_allocatable' => max(0, (int) floor((float) $max['disk'])),
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
