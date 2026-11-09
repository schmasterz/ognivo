<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/**
 * API Resource for tenant administration views.
 *
 * @property-read User $resource
 *
 * @mixin User
 */
final class AdminUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        $user = $this->resource;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'status' => $user->status,
            'email_verified_at' => $user->email_verified_at?->toISOString(),
            'quota_group_id' => $user->quota_group_id,
            'virtual_machines_count' => $this->whenCounted('virtualMachines'),
            'ip_allocations_count' => $this->whenCounted('ipAllocations'),
            'created_at' => $user->created_at?->toISOString(),
            'updated_at' => $user->updated_at?->toISOString(),
            'roles' => $this->whenLoaded('roles'),
            'quota' => $this->whenLoaded('quota', fn (): ?UserQuotaResource => $user->quota !== null ? new UserQuotaResource($user->quota) : null),
            'quota_group' => $this->whenLoaded('quotaGroup', fn (): ?QuotaGroupResource => $user->quotaGroup !== null ? new QuotaGroupResource($user->quotaGroup) : null),
        ];
    }
}
