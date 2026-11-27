<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\NetworkStatus;
use App\Models\IpAllocation;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\ProvisioningCompensator;
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
        $isAdmin = $user?->hasRole('admin') ?? false;

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

        $rawFailureReason = is_array($vm->metadata) && isset($vm->metadata['raw_failure_reason']) && is_string($vm->metadata['raw_failure_reason'])
            ? $vm->metadata['raw_failure_reason']
            : (is_array($vm->metadata) && isset($vm->metadata['failure_reason']) && is_string($vm->metadata['failure_reason']) ? $vm->metadata['failure_reason'] : null);

        $sanitizedFailureReason = is_array($vm->metadata) && isset($vm->metadata['failure_reason']) && is_string($vm->metadata['failure_reason'])
            ? (isset($vm->metadata['raw_failure_reason']) ? $vm->metadata['failure_reason'] : ProvisioningCompensator::sanitizeFailureReason($vm->metadata['failure_reason']))
            : ($rawFailureReason !== null ? ProvisioningCompensator::sanitizeFailureReason($rawFailureReason) : null);

        return [
            'id' => $vm->id,
            'user_id' => $vm->user_id,
            'host_id' => $vm->host_id,
            'name' => $vm->name,
            'incus_project' => $this->when($isAdmin, $vm->incus_project),
            'instance_type' => $vm->instance_type,
            'status' => (string) $vm->status,
            'failure_reason' => $isAdmin ? ($rawFailureReason ?? $sanitizedFailureReason) : $sanitizedFailureReason,
            'raw_failure_reason' => $this->when($isAdmin, $rawFailureReason),
            'cpu_cores' => $vm->cpu_cores,
            'ram_mb' => $vm->ram_mb,
            'disk_gb' => $vm->disk_gb,
            'os_image' => $vm->os_image,
            'os_image_alias' => $vm->image->alias ?? $vm->os_image,
            'ipv4_address' => $vm->ipv4_address,
            'ipv6_address' => $vm->ipv6_address,
            'mac_address' => $macAddress,
            'network_status' => $vm->network_status?->value,
            'network' => [
                'primary' => [
                    'address' => $address,
                    'family' => 4,
                    'status' => $vm->network_status?->value,
                    'mac_address' => $macAddress,
                ],
                'settings' => [
                    'prefix' => $prefix,
                    'gateway' => $pool?->gateway,
                    'dns' => $pool?->dns_servers,
                    'mode' => $pool?->network_mode,
                ],
                'observed_at' => $vm->network_status === NetworkStatus::Observed ? $vm->updated_at?->toISOString() : null,
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
