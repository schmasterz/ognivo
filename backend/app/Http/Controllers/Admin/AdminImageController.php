<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\DTO\Cast;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateImageRequest;
use App\Http\Requests\Admin\UpdateImageRequest;
use App\Http\Resources\ImageResource;
use App\Models\Host;
use App\Models\Image;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\RouteAttributes\Attributes\Delete;
use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Post;
use Spatie\RouteAttributes\Attributes\Put;

/**
 * Controller for administrator management of the OS Image catalog and Compute Node associations.
 */
final class AdminImageController extends Controller
{
    /**
     * List all images in allowlist catalog with optional filtering.
     */
    #[Get('admin/images', name: 'admin.images.index', middleware: ['auth:sanctum', 'active', 'admin'])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Image::with('hosts')->latest();

        if ($request->filled('search')) {
            $search = Cast::string($request->input('search'));
            $query->where('name', 'like', "%{$search}%")
                ->orWhere('source', 'like', "%{$search}%");
        }

        if ($request->filled('os_type')) {
            $query->where('os_type', Cast::string($request->input('os_type')));
        }

        if ($request->has('enabled') && $request->input('enabled') !== '') {
            $query->where('enabled', $request->boolean('enabled'));
        }

        if ($request->filled('host_id')) {
            $hostId = $request->integer('host_id');
            $query->whereHas('hosts', fn ($q) => $q->where('hosts.id', $hostId));
        }

        $perPage = max(1, min(100, $request->integer('per_page', 25)));
        $images = $query->paginate($perPage);

        return ImageResource::collection($images);
    }

    /**
     * Create a new approved image allowlist entry and link compute nodes.
     */
    #[Post('admin/images', name: 'admin.images.store', middleware: ['auth:sanctum', 'active', 'admin'])]
    public function store(CreateImageRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        /** @var Image $image */
        $image = Image::create([
            'name' => Cast::string($data['name'] ?? ''),
            'source' => Cast::string($data['source'] ?? ''),
            'protocol' => Cast::string($data['protocol'] ?? 'simplestreams'),
            'os_type' => Cast::string($data['os_type'] ?? 'linux'),
            'enabled' => (bool) ($data['enabled'] ?? true),
        ]);

        if (isset($data['host_ids']) && is_array($data['host_ids'])) {
            $image->hosts()->sync($data['host_ids']);
        } else {
            $image->hosts()->sync(Host::pluck('id')->all());
        }

        $image->load('hosts');

        return response()->json([
            'message' => 'Image added to allowlist successfully.',
            'image' => new ImageResource($image),
        ], 201);
    }

    /**
     * Display a specific image.
     */
    #[Get('admin/images/{image}', name: 'admin.images.show', middleware: ['auth:sanctum', 'active', 'admin'])]
    public function show(Image $image): JsonResponse
    {
        $image->load('hosts');

        return response()->json([
            'image' => new ImageResource($image),
        ]);
    }

    /**
     * Update an approved image and synchronize its compute node associations.
     */
    #[Put('admin/images/{image}', name: 'admin.images.update', middleware: ['auth:sanctum', 'active', 'admin'])]
    public function update(UpdateImageRequest $request, Image $image): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $updateData = [];
        if (array_key_exists('name', $data)) {
            $updateData['name'] = Cast::string($data['name']);
        }
        if (array_key_exists('source', $data)) {
            $updateData['source'] = Cast::string($data['source']);
        }
        if (array_key_exists('protocol', $data)) {
            $updateData['protocol'] = Cast::string($data['protocol']);
        }
        if (array_key_exists('os_type', $data)) {
            $updateData['os_type'] = Cast::string($data['os_type']);
        }
        if (array_key_exists('enabled', $data)) {
            $updateData['enabled'] = (bool) $data['enabled'];
        }

        if (! empty($updateData)) {
            $image->update($updateData);
        }

        if (array_key_exists('host_ids', $data) && is_array($data['host_ids'])) {
            $image->hosts()->sync($data['host_ids']);
        }

        $image->load('hosts');

        return response()->json([
            'message' => 'Image updated successfully.',
            'image' => new ImageResource($image),
        ]);
    }

    /**
     * Delete an image from the allowlist.
     */
    #[Delete('admin/images/{image}', name: 'admin.images.destroy', middleware: ['auth:sanctum', 'active', 'admin'])]
    public function destroy(Image $image): JsonResponse
    {
        $image->delete();

        return response()->json([
            'message' => 'Image removed from allowlist successfully.',
        ]);
    }
}
