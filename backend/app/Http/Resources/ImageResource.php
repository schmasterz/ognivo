<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Image;
use App\Models\User;
use App\Support\Cast;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/**
 * API Resource transforming Image allowlist entity.
 *
 * @property-read Image $resource
 *
 * @mixin Image
 */
final class ImageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        $image = $this->resource;
        /** @var User|null $user */
        $user = $request->user();
        $isAdmin = $user?->hasRole('admin') ?? false;

        return [
            'id' => $image->id,
            'name' => $image->name,
            'alias' => $image->alias,
            'source' => $image->source,
            'protocol' => $image->protocol,
            'os_type' => $image->os_type,
            'enabled' => (bool) $image->enabled,
            'attached' => $this->when(
                array_key_exists('attached', $image->getAttributes()),
                fn () => (bool) $image->getAttribute('attached')
            ),
            'hosts' => $isAdmin
                ? HostNodeResource::collection($this->whenLoaded('hosts'))
                : TenantHostResource::collection($this->whenLoaded('hosts')),
            'host_ids' => $image->relationLoaded('hosts') ? $image->hosts->pluck('id')->values()->all() : [],
            'active_vms_count' => $this->when(
                $isAdmin,
                fn (): int => $image->relationLoaded('virtualMachines')
                    ? $image->virtualMachines->where('status', '!=', 'deleted')->count()
                    : Cast::int($image->getAttribute('active_vms_count') ?? $image->virtualMachines()->whereNotIn('status', ['deleted'])->count())
            ),
            'created_at' => $image->created_at?->toISOString(),
            'updated_at' => $image->updated_at?->toISOString(),
        ];
    }
}
