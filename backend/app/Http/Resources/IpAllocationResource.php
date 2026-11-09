<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\IpAllocation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/**
 * API Resource transforming IpAllocation entity.
 *
 * @property-read IpAllocation $resource
 *
 * @mixin IpAllocation
 */
final class IpAllocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        $allocation = $this->resource;
        /** @var User|null $user */
        $user = $request->user();
        $isAdmin = $user !== null && $user->hasRole('admin');

        $cooldownRemaining = 0;
        $status = $allocation->status;
        if ($allocation->status === 'cooling' && $allocation->released_at !== null) {
            $cooldownSeconds = $allocation->pool?->cooldown_seconds ?? 300;
            $coolingUntil = $allocation->released_at->copy()->addSeconds($cooldownSeconds);
            $diff = (int) now()->diffInSeconds($coolingUntil, false);
            if ($diff <= 0) {
                $cooldownRemaining = 0;
                $status = 'released';
            } else {
                $cooldownRemaining = $diff;
            }
        }

        if (! $isAdmin) {
            return [
                'id' => $allocation->id,
                'address' => $allocation->ip_string,
                'ip_string' => $allocation->ip_string,
                'mac_address' => $allocation->mac_address,
                'status' => $status,
                'virtual_machine_id' => $allocation->virtual_machine_id,
                'virtual_machine' => $allocation->virtualMachine !== null ? [
                    'id' => $allocation->virtualMachine->id,
                    'name' => $allocation->virtualMachine->name,
                    'status' => (string) $allocation->virtualMachine->status,
                ] : null,
                'allocated_at' => $allocation->allocated_at->toISOString(),
                'released_at' => $allocation->released_at?->toISOString(),
                'cooldown_remaining_seconds' => $cooldownRemaining,
                'created_at' => $allocation->created_at?->toISOString(),
                'updated_at' => $allocation->updated_at?->toISOString(),
            ];
        }

        return [
            'id' => $allocation->id,
            'pool_id' => $allocation->pool_id,
            'address' => $allocation->ip_string,
            'ip_string' => $allocation->ip_string,
            'mac_address' => $allocation->mac_address,
            'status' => $status,
            'virtual_machine_id' => $allocation->virtual_machine_id,
            'user_id' => $allocation->user_id,
            'virtual_machine' => $allocation->virtualMachine !== null ? [
                'id' => $allocation->virtualMachine->id,
                'name' => $allocation->virtualMachine->name,
                'status' => (string) $allocation->virtualMachine->status,
            ] : null,
            'user' => $allocation->user !== null ? [
                'id' => $allocation->user->id,
                'name' => $allocation->user->name,
                'email' => $allocation->user->email,
            ] : null,
            'pool' => $allocation->pool !== null ? [
                'id' => $allocation->pool->id,
                'name' => $allocation->pool->name,
                'cidr' => $allocation->pool->cidr,
                'host_id' => $allocation->pool->host_id,
                'host' => $allocation->pool->host !== null ? [
                    'id' => $allocation->pool->host->id,
                    'name' => $allocation->pool->host->name,
                    'region' => $allocation->pool->host->region,
                    'driver' => $allocation->pool->host->driver,
                    'is_active' => $allocation->pool->host->is_active,
                ] : null,
            ] : null,
            'ip_pool' => $allocation->pool !== null ? [
                'id' => $allocation->pool->id,
                'name' => $allocation->pool->name,
                'cidr' => $allocation->pool->cidr,
                'host_id' => $allocation->pool->host_id,
                'host' => $allocation->pool->host !== null ? [
                    'id' => $allocation->pool->host->id,
                    'name' => $allocation->pool->host->name,
                    'region' => $allocation->pool->host->region,
                    'driver' => $allocation->pool->host->driver,
                    'is_active' => $allocation->pool->host->is_active,
                ] : null,
            ] : null,
            'allocated_at' => $allocation->allocated_at->toISOString(),
            'released_at' => $allocation->released_at?->toISOString(),
            'cooldown_remaining_seconds' => $cooldownRemaining,
            'created_at' => $allocation->created_at?->toISOString(),
            'updated_at' => $allocation->updated_at?->toISOString(),
        ];
    }
}
