<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\IpAllocation;
use App\Models\User;
use App\Models\VirtualMachine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/**
 * API Resource transforming VirtualMachine entity.
 *
 * @property-read VirtualMachine $resource
 *
 * @mixin VirtualMachine
 */
final class VirtualMachineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        $vm = $this->resource;
        /** @var User|null $user */
        $user = $request->user();
        $isAdmin = $user !== null && $user->hasRole('admin');

        /** @var IpAllocation|null $allocation */
        $allocation = $vm->relationLoaded('primaryAllocation')
            ? $vm->primaryAllocation
            : $vm->primaryAllocation()->with('pool')->first();

        $pool = $allocation?->pool;

        $prefix = null;
        $cidr = $pool !== null && ! empty($pool->cidr) ? $pool->cidr : $pool?->subnet;
        if ($cidr !== null && ! empty($cidr)) {
            $parts = explode('/', $cidr);
            $prefix = isset($parts[1]) ? (int) $parts[1] : 32;
        }

        $address = $allocation !== null ? $allocation->ip_string : $vm->ipv4_address;
        $macAddress = $allocation?->mac_address;

        return [
            'id' => $vm->id,
            'user_id' => $vm->user_id,
            'host_id' => $vm->host_id,
            'name' => $vm->name,
            'incus_project' => $vm->incus_project,
            'instance_type' => $vm->instance_type,
            'status' => (string) $vm->status,
            'cpu_cores' => $vm->cpu_cores,
            'ram_mb' => $vm->ram_mb,
            'disk_gb' => $vm->disk_gb,
            'os_image' => $vm->os_image,
            'ipv4_address' => $vm->ipv4_address,
            'ipv6_address' => $vm->ipv6_address,
            'mac_address' => $macAddress,
            'network_status' => $vm->network_status,
            'network' => [
                'primary' => [
                    'address' => $address,
                    'family' => 4,
                    'status' => $vm->network_status,
                    'mac_address' => $macAddress,
                ],
                'settings' => [
                    'prefix' => $prefix,
                    'gateway' => $pool?->gateway,
                    'dns' => $pool?->dns_servers,
                    'mode' => $pool?->network_mode,
                ],
                'observed_at' => $vm->network_status === 'observed' ? $vm->updated_at?->toISOString() : null,
            ],
            'created_at' => $vm->created_at?->toISOString(),
            'updated_at' => $vm->updated_at?->toISOString(),
            'host' => $this->whenLoaded('host', fn (): ?JsonResource => $vm->host !== null ? ($isAdmin ? new HostNodeResource($vm->host) : new TenantHostResource($vm->host)) : null),
            'user' => $this->whenLoaded('user', fn (): ?array => $vm->user !== null ? [
                'id' => $vm->user->id,
                'name' => $vm->user->name,
                'email' => $vm->user->email,
            ] : null),
        ];
    }
}
