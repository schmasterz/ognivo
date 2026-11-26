<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Audit\AuditAction;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Audit\AuditOutcome;
use App\DTO\Cast;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateImageRequest;
use App\Http\Requests\Admin\UpdateImageRequest;
use App\Http\Requests\Admin\ValidateImageRequest;
use App\Http\Resources\ImageResource;
use App\Models\Image;
use App\Services\Hypervisor\RemoteImageValidatorService;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use JsonException;
use Spatie\RouteAttributes\Attributes\Delete;
use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Post;
use Spatie\RouteAttributes\Attributes\Put;

/**
 * Controller for administrator management of the OS Image catalog and Compute Node associations.
 */
final class AdminImageController extends Controller
{
    public function __construct(
        private readonly ?AuditLogger $auditLogger = null,
    ) {}

    private function logger(): AuditLogger
    {
        return $this->auditLogger ?? app(AuditLogger::class);
    }

    /**
     * List all images in allowlist catalog with keyset cursor pagination and optional filtering.
     */
    #[Get('admin/images', name: 'admin.images.index', middleware: ['auth:sanctum', 'active', 'admin'])]
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string'],
            'os_type' => ['nullable', 'string'],
            'protocol' => ['nullable', 'string'],
            'enabled' => ['nullable'],
            'host_id' => ['nullable', 'integer', 'exists:hosts,id'],
            'attachment' => ['nullable', 'string', 'in:all,attached,detached', Rule::prohibitedIf(! $request->filled('host_id'))],
            'cursor' => [
                'nullable',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || $value === '') {
                        return;
                    }
                    $decodedJson = base64_decode($value, true);
                    if ($decodedJson === false) {
                        $fail('The cursor is invalid.');

                        return;
                    }
                    try {
                        $decoded = json_decode($decodedJson, true, 512, JSON_THROW_ON_ERROR);
                    } catch (JsonException) {
                        $fail('The cursor is invalid.');

                        return;
                    }
                    if (! is_array($decoded) || ! isset($decoded['id']) || ! is_numeric($decoded['id']) || (int) $decoded['id'] <= 0) {
                        $fail('The cursor is invalid.');

                        return;
                    }
                },
            ],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = $request->integer('per_page', 50);
        $perPage = max(1, min(100, $perPage));

        $cursorId = null;
        if ($request->filled('cursor')) {
            $cursor = Cast::string($request->input('cursor'));
            $decodedJson = (string) base64_decode($cursor, true);
            /** @var array{id: int|string} $decoded */
            $decoded = json_decode($decodedJson, true, 512, JSON_THROW_ON_ERROR);
            $cursorId = (int) $decoded['id'];
        }

        /** @var Builder<Image> $catalogQuery */
        $catalogQuery = Image::query();

        $hasSearch = $request->filled('search');
        if ($hasSearch) {
            $search = Cast::string($request->input('search'));
            $escaped = addcslashes($search, '%_\\');
            $catalogQuery->where(function (Builder $q) use ($escaped): void {
                $q->where('images.name', 'like', "%{$escaped}%")
                    ->orWhere('images.alias', 'like', "%{$escaped}%")
                    ->orWhere('images.source', 'like', "%{$escaped}%");
            });
        }

        $hasOsType = $request->filled('os_type');
        if ($hasOsType) {
            $catalogQuery->where('images.os_type', Cast::string($request->input('os_type')));
        }

        $hasProtocol = $request->filled('protocol');
        if ($hasProtocol) {
            $catalogQuery->where('images.protocol', Cast::string($request->input('protocol')));
        }

        $hasEnabled = $request->has('enabled') && $request->input('enabled') !== '' && $request->input('enabled') !== null;
        if ($hasEnabled) {
            $catalogQuery->where('images.enabled', $request->boolean('enabled'));
        }

        $hasCatalogFilters = $hasSearch || $hasOsType || $hasProtocol || $hasEnabled;

        $hostId = $request->filled('host_id') ? $request->integer('host_id') : null;

        $overallCatalogCount = Image::count();
        $overallAttachedCount = $hostId !== null
            ? DB::table('host_image')->where('host_id', $hostId)->count()
            : null;

        if (! $hasCatalogFilters) {
            $filteredCatalogCount = $overallCatalogCount;
            $filteredAttachedCount = $overallAttachedCount;
        } else {
            $filteredCatalogCount = (clone $catalogQuery)->count();
            $filteredAttachedCount = $hostId !== null
                ? (clone $catalogQuery)->join('host_image', function (JoinClause $join) use ($hostId): void {
                    $join->on('host_image.image_id', '=', 'images.id')
                        ->where('host_image.host_id', '=', $hostId);
                })->count()
                : null;
        }

        /** @var Builder<Image> $query */
        $query = (clone $catalogQuery);

        if ($hostId !== null) {
            $attachment = Cast::string($request->input('attachment', 'all'));
            if ($attachment === 'attached') {
                $query->join('host_image', function (JoinClause $join) use ($hostId): void {
                    $join->on('host_image.image_id', '=', 'images.id')
                        ->where('host_image.host_id', '=', $hostId);
                });
            } elseif ($attachment === 'detached') {
                $query->leftJoin('host_image', function (JoinClause $join) use ($hostId): void {
                    $join->on('host_image.image_id', '=', 'images.id')
                        ->where('host_image.host_id', '=', $hostId);
                })->whereNull('host_image.id');
            } else { // 'all'
                $query->leftJoin('host_image', function (JoinClause $join) use ($hostId): void {
                    $join->on('host_image.image_id', '=', 'images.id')
                        ->where('host_image.host_id', '=', $hostId);
                });
            }

            $query->select('images.*')
                ->selectRaw('CASE WHEN host_image.id IS NULL THEN 0 ELSE 1 END AS attached');
        } else {
            $query->select('images.*');
        }

        $query->withCount([
            'virtualMachines as active_vms_count' => function (Builder $q): void {
                $q->whereNotIn('status', ['deleted']);
            },
        ]);

        if ($cursorId !== null) {
            $query->where('images.id', '<', $cursorId);
        }

        $query->orderByDesc('images.id');
        $query->limit($perPage + 1);

        /** @var Collection<int, Image> $results */
        $results = $query->get();

        if ($results->count() > $perPage) {
            /** @var Collection<int, Image> $items */
            $items = $results->take($perPage);
            /** @var Image $lastItem */
            $lastItem = $items->last();
            $nextCursor = base64_encode((string) json_encode(['id' => $lastItem->id]));
        } else {
            $items = $results;
            $nextCursor = null;
        }

        if ($hostId === null) {
            $items->load('hosts');
        }

        return response()->json([
            'data' => ImageResource::collection($items),
            'next_cursor' => $nextCursor,
            'per_page' => $perPage,
            'counts' => [
                'filtered' => [
                    'catalog' => $filteredCatalogCount,
                    'attached' => $filteredAttachedCount,
                ],
                'overall' => [
                    'catalog' => $overallCatalogCount,
                    'attached' => $overallAttachedCount,
                ],
            ],
        ]);
    }

    /**
     * Pre-flight validate an image against its remote server.
     */
    #[Post('admin/images/validate', name: 'admin.images.validate', middleware: ['auth:sanctum', 'active', 'admin'])]
    public function validateRemote(ValidateImageRequest $request, RemoteImageValidatorService $validator): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $result = $validator->validate(
            source: Cast::string($data['source']),
            imageName: Cast::string($data['name']),
            protocol: Cast::string($data['protocol'] ?? 'simplestreams'),
            osType: isset($data['os_type']) ? Cast::string($data['os_type']) : null,
        );

        return response()->json($result->toArray());
    }

    /**
     * Create a new approved image allowlist entry and link compute nodes.
     */
    #[Post('admin/images', name: 'admin.images.store', middleware: ['auth:sanctum', 'active', 'admin'])]
    public function store(CreateImageRequest $request, RemoteImageValidatorService $validator): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        if ($this->shouldValidateRemoteImages($request)) {
            $this->assertRemoteImageValid(
                validator: $validator,
                source: Cast::string($data['source'] ?? ''),
                imageName: Cast::string($data['name'] ?? ''),
                protocol: Cast::string($data['protocol'] ?? 'simplestreams'),
                osType: isset($data['os_type']) ? Cast::string($data['os_type']) : null,
            );
        }

        /** @var Image $image */
        $image = DB::transaction(function () use ($data): Image {
            /** @var Image $image */
            $image = Image::create([
                'name' => Cast::string($data['name'] ?? ''),
                'alias' => Cast::string($data['alias'] ?? ($data['name'] ?? '')),
                'source' => Cast::string($data['source'] ?? ''),
                'protocol' => Cast::string($data['protocol'] ?? 'simplestreams'),
                'os_type' => Cast::string($data['os_type'] ?? 'linux'),
                'enabled' => (bool) ($data['enabled'] ?? true),
            ]);

            if (isset($data['host_ids']) && is_array($data['host_ids'])) {
                $image->hosts()->sync($data['host_ids']);
            }

            return $image;
        });

        $image->load('hosts');

        $this->logger()->record(new AuditEntry(
            action: AuditAction::ImageCreated,
            outcome: AuditOutcome::Success,
            subject: $image,
            tenantUserId: null,
            visibility: 'admin',
            context: [
                'name' => $image->name,
                'alias' => $image->alias,
                'source' => $image->source,
                'protocol' => $image->protocol,
                'os_type' => $image->os_type,
                'enabled' => $image->enabled,
                'attached_hosts_count' => $image->hosts->count(),
            ],
        ));

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
    public function update(UpdateImageRequest $request, Image $image, RemoteImageValidatorService $validator): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $remoteFieldChanged = array_key_exists('name', $data)
            || array_key_exists('source', $data)
            || array_key_exists('protocol', $data);

        if ($remoteFieldChanged && $this->shouldValidateRemoteImages($request)) {
            $this->assertRemoteImageValid(
                validator: $validator,
                source: Cast::string($data['source'] ?? $image->source),
                imageName: Cast::string($data['name'] ?? $image->name),
                protocol: Cast::string($data['protocol'] ?? $image->protocol),
                osType: isset($data['os_type']) ? Cast::string($data['os_type']) : $image->os_type,
            );
        }

        $oldAttributes = [
            'name' => $image->name,
            'alias' => $image->alias,
            'source' => $image->source,
            'protocol' => $image->protocol,
            'os_type' => $image->os_type,
            'enabled' => $image->enabled,
        ];
        $oldHostCount = $image->hosts()->count();

        $updateData = [];
        if (array_key_exists('name', $data)) {
            $updateData['name'] = Cast::string($data['name']);
        }
        if (array_key_exists('alias', $data)) {
            $updateData['alias'] = Cast::string($data['alias']);
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

        $changes = [];
        foreach ($updateData as $key => $newVal) {
            if (array_key_exists($key, $oldAttributes) && $oldAttributes[$key] !== $newVal) {
                $changes[$key] = [
                    'old' => $oldAttributes[$key],
                    'new' => $newVal,
                ];
            }
        }
        if (array_key_exists('host_ids', $data) && is_array($data['host_ids'])) {
            $newHostCount = count($data['host_ids']);
            if ($oldHostCount !== $newHostCount) {
                $changes['attached_hosts'] = [
                    'old_count' => $oldHostCount,
                    'new_count' => $newHostCount,
                ];
            }
        }

        $this->logger()->record(new AuditEntry(
            action: AuditAction::ImageUpdated,
            outcome: AuditOutcome::Success,
            subject: $image,
            tenantUserId: null,
            visibility: 'admin',
            context: [
                'name' => $image->name,
                'alias' => $image->alias,
                'changes' => $changes,
            ],
        ));

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
        $activeVmsCount = $image->virtualMachines()->whereNotIn('status', ['deleted'])->count();
        if ($activeVmsCount > 0) {
            throw ValidationException::withMessages([
                'image' => ["Cannot delete image '{$image->alias}' because it is currently used by {$activeVmsCount} active virtual machine(s)."],
            ]);
        }

        $this->logger()->record(new AuditEntry(
            action: AuditAction::ImageDeleted,
            outcome: AuditOutcome::Success,
            subject: $image,
            tenantUserId: null,
            visibility: 'admin',
            context: [
                'name' => $image->name,
                'alias' => $image->alias,
                'source' => $image->source,
                'protocol' => $image->protocol,
                'unlinked_hosts_count' => $image->hosts()->count(),
            ],
        ));

        $image->delete();

        return response()->json([
            'message' => 'Image removed from allowlist successfully.',
        ]);
    }

    private function shouldValidateRemoteImages(Request $request): bool
    {
        return ! $request->boolean('skip_remote_validation')
            && Cast::bool(config('hypervisor.validate_remote_images', App::environment('testing') === false));
    }

    private function assertRemoteImageValid(
        RemoteImageValidatorService $validator,
        string $source,
        string $imageName,
        string $protocol,
        ?string $osType,
    ): void {
        $validation = $validator->validate(
            source: $source,
            imageName: $imageName,
            protocol: $protocol,
            osType: $osType,
        );

        if (! $validation->isValid) {
            throw ValidationException::withMessages([
                'name' => [$validation->error ?? 'The specified image was not found or is invalid on the remote server.'],
            ]);
        }
    }
}
