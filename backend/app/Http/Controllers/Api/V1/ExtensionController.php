<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Extension\ExtensionMetadata;
use App\Services\Extension\ExtensionRegistry;
use Illuminate\Http\JsonResponse;
use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Where;

/**
 * Administrative controller for listing and inspecting discovered Ognivo extensions.
 */
final class ExtensionController extends Controller
{
    public function __construct(
        private readonly ExtensionRegistry $registry,
    ) {}

    /**
     * List all discovered extensions and their core compatibility status.
     */
    #[Get('extensions', name: 'extensions.index', middleware: ['auth:sanctum', 'active', 'admin'])]
    public function index(): JsonResponse
    {
        $extensions = array_map(
            fn (ExtensionMetadata $extension): array => $this->serializeExtension($extension),
            array_values($this->registry->all())
        );

        return response()->json(['data' => $extensions]);
    }

    /**
     * Inspect detailed metadata for a single discovered extension by name.
     */
    #[Get('extensions/{name}', name: 'extensions.show', middleware: ['auth:sanctum', 'active', 'admin'])]
    #[Where('name', '.*')]
    public function show(string $name): JsonResponse
    {
        $extension = $this->registry->get($name);

        if ($extension === null) {
            abort(404, 'Extension not found.');
        }

        return response()->json([
            'data' => $this->serializeExtension($extension),
        ]);
    }

    /**
     * Serialize an extension metadata DTO and its compatibility flag into an API array.
     *
     * @return array{
     *     name: string,
     *     version: string,
     *     description: string,
     *     author: string,
     *     ognivo_version_constraint: string,
     *     providers: list<string>,
     *     event_subscribers: list<string>,
     *     compatible: bool,
     * }
     */
    private function serializeExtension(ExtensionMetadata $extension): array
    {
        return [
            'name' => $extension->name,
            'version' => $extension->version,
            'description' => $extension->description,
            'author' => $extension->author,
            'ognivo_version_constraint' => $extension->ognivoVersionConstraint,
            'providers' => $extension->providers,
            'event_subscribers' => $extension->eventSubscribers,
            'compatible' => $this->registry->isCompatible($extension),
        ];
    }
}
