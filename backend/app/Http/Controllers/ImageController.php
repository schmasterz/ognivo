<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\ImageResource;
use App\Models\Image;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\RouteAttributes\Attributes\Get;

/**
 * Controller providing active OS images available to tenant users for workload provisioning.
 */
final class ImageController extends Controller
{
    /**
     * List active and compatible images for user provisioning.
     */
    #[Get('images', name: 'images.index', middleware: ['auth:sanctum', 'active'])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Image::with('hosts')->where('enabled', true);

        // Filter by compute node if specified
        if ($request->filled('host_id')) {
            $hostId = $request->integer('host_id');
            $query->whereHas('hosts', fn ($q) => $q->where('hosts.id', $hostId));
        }

        // If user wants a container, only Linux images are compatible
        if ($request->string('instance_type')->value() === 'container') {
            $query->where('os_type', 'linux');
        }

        $images = $query->orderBy('name')->get();

        return ImageResource::collection($images);
    }
}
