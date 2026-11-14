<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Image;
use App\Models\User;
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
            'source' => $image->source,
            'protocol' => $image->protocol,
            'os_type' => $image->os_type,
            'enabled' => (bool) $image->enabled,
            'hosts' => $isAdmin
                ? HostNodeResource::collection($this->whenLoaded('hosts'))
                : TenantHostResource::collection($this->whenLoaded('hosts')),
            'host_ids' => $image->relationLoaded('hosts') ? $image->hosts->pluck('id')->values()->all() : [],
            'created_at' => $image->created_at?->toISOString(),
            'updated_at' => $image->updated_at?->toISOString(),
        ];
    }
}
