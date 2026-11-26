<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\DTO\Cast;
use App\DTO\ImageSelectionDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateHostRequest;
use App\Http\Requests\Admin\ImageSelectionRules;
use App\Http\Requests\Admin\UpdateHostRequest;
use App\Http\Resources\HostNodeResource;
use App\Http\Resources\TenantHostResource;
use App\Models\Host;
use App\Models\User;
use App\Services\HostCapacityGuard;
use App\Services\HostNodeService;
use App\Services\Hypervisor\TenantProjectProvisioner;
use App\Services\ImageAttachmentService;
use App\Services\IncusConnectionChecker;
use App\Services\IncusTrustService;
use App\Services\PanelCertificateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\RouteAttributes\Attributes\Delete;
use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Post;
use Spatie\RouteAttributes\Attributes\Put;

/**
 * Controller managing Incus / LXD host nodes and multi-region hypervisor clusters.
 */
final class HostNodeController extends Controller
{
    public function __construct(
        private readonly HostNodeService $hostNodeService,
        private readonly IncusTrustService $trustService,
        private readonly IncusConnectionChecker $connectionChecker,
        private readonly PanelCertificateService $certService,
        private readonly ImageAttachmentService $imageAttachmentService,
    ) {}

    /**
     * List active host nodes for user provisioning.
     * (Accessible by all authenticated users)
     */
    #[Get('hosts', name: 'hosts.active', middleware: ['auth:sanctum', 'active'])]
    public function listActive(Request $request): JsonResponse
    {
        $hosts = $this->hostNodeService->listActive();
        $this->attachCommittedUsage($hosts);

        /** @var User|null $user */
        $user = $request->user();
        $isAdmin = $user !== null && $user->hasRole('admin');

        $resource = $isAdmin
            ? HostNodeResource::collection($hosts)
            : TenantHostResource::collection($hosts);

        return response()->json([
            'data' => $resource,
        ]);
    }

    /**
     * List all host nodes with infrastructure stats for admins.
     */
    #[Get('admin/hosts', name: 'admin.hosts.index', middleware: ['auth:sanctum', 'active', 'admin'])]
    public function index(Request $request): JsonResponse
    {
        /** @var array{search?: string|null, status?: string|null, is_active?: bool|null, driver?: string|null} $filters */
        $filters = [
            'search' => $request->filled('search') ? $request->string('search')->trim()->value() : null,
            'status' => $request->filled('status') ? $request->string('status')->trim()->value() : null,
            'is_active' => $request->filled('is_active')
                ? filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                : null,
            'driver' => $request->filled('driver') ? $request->string('driver')->trim()->value() : null,
        ];

        $perPage = max(1, min(100, $request->integer('per_page', 10)));
        $paginator = $this->hostNodeService->paginateAll($perPage, $filters);

        /** @var list<Host> $hosts */
        $hosts = $paginator->items();
        $this->attachCommittedUsage($hosts);

        return response()->json([
            'data' => HostNodeResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ]);
    }

    /**
     * Create a new host node (supports token onboarding, certificate pinning, or direct).
     */
    #[Post('admin/hosts', name: 'admin.hosts.store', middleware: ['auth:sanctum', 'active', 'admin'])]
    public function store(CreateHostRequest $request): JsonResponse
    {
        // Auto-generate active panel certificate if none exists when adding first host
        if ($this->certService->getActiveCertificate() === null) {
            $this->certService->generate();
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();
        $token = isset($validated['token']) && is_string($validated['token']) ? trim($validated['token']) : '';
        $expectedFingerprint = isset($validated['expected_fingerprint']) && is_string($validated['expected_fingerprint']) ? trim($validated['expected_fingerprint']) : '';
        $driver = isset($validated['driver']) && is_string($validated['driver']) ? $validated['driver'] : 'incus';

        $extraAttributes = $this->buildOnboardingAttributes($validated, $driver);

        $dto = $request->toDTO();

        $isTrustToken = false;
        if ($token !== '' && $driver !== 'fake') {
            $decoded = base64_decode($token, true);
            if ($decoded !== false && json_validate($decoded)) {
                $isTrustToken = true;
            }
        }

        if ($isTrustToken) {
            $host = $this->trustService->addHostWithToken(Cast::string($validated['name'] ?? ''), $token, $extraAttributes);
            if ($dto->images !== null) {
                $this->imageAttachmentService->apply($host, $dto->images);
            }
        } elseif ($expectedFingerprint !== '' && $driver !== 'fake') {
            $address = Cast::string($validated['endpoint_url'] ?? $validated['address'] ?? '');
            $host = $this->trustService->addHostWithCertificate(Cast::string($validated['name'] ?? ''), $address, $expectedFingerprint, $extraAttributes);
            if ($dto->images !== null) {
                $this->imageAttachmentService->apply($host, $dto->images);
            }
        } else {
            $host = $this->hostNodeService->create($dto);
            if ($driver === 'fake') {
                $this->connectionChecker->check($host);
            }
        }

        return response()->json([
            'message' => 'Compute node created successfully.',
            'host' => new HostNodeResource($host),
        ], 201);
    }

    /**
     * Connect host using single-use trust token (Part 1 primary action).
     */
    #[Post('admin/hosts/connect-token', name: 'admin.hosts.connect_token', middleware: ['auth:sanctum', 'active', 'admin'])]
    public function connectWithToken(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $validated */
        $validated = $request->validate(array_merge([
            'name' => ['required', 'string', 'max:255', 'unique:hosts,name'],
            'token' => ['required', 'string'],
            'driver' => ['nullable', 'string', Rule::in(['fake', 'incus', 'lxd'])],
            'region' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'total_cpu_cores' => ['nullable', 'integer', 'min:1'],
            'total_ram_mb' => ['nullable', 'integer', 'min:1024'],
            'total_storage_gb' => ['nullable', 'integer', 'min:10'],
            'cpu_overcommit' => ['nullable', 'numeric', 'min:1', 'max:16'],
            'ram_overcommit' => ['nullable', 'numeric', 'min:1', 'max:4'],
            'disk_overcommit' => ['nullable', 'numeric', 'min:1', 'max:4'],
            'settings' => ['nullable', 'array'],
        ], ImageSelectionRules::rules()));

        $extraAttributes = $this->buildOnboardingAttributes($validated);

        $host = $this->trustService->addHostWithToken(Cast::string($validated['name'] ?? ''), Cast::string($validated['token'] ?? ''), $extraAttributes);

        if (isset($validated['images']) && is_array($validated['images'])) {
            /** @var array<string, mixed> $imagesPayload */
            $imagesPayload = $validated['images'];
            $selection = ImageSelectionDTO::fromArray($imagesPayload);
            $this->imageAttachmentService->apply($host, $selection);
        }

        return response()->json([
            'message' => 'Compute node onboarded and trusted successfully via token.',
            'host' => new HostNodeResource($host),
        ], 201);
    }

    /**
     * Add host with pre-trusted certificate (Part 1 secondary action).
     */
    #[Post('admin/hosts/add-certificate', name: 'admin.hosts.add_certificate', middleware: ['auth:sanctum', 'active', 'admin'])]
    public function addWithCertificate(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $validated */
        $validated = $request->validate(array_merge([
            'name' => ['required', 'string', 'max:255', 'unique:hosts,name'],
            'address' => ['required', 'string', 'max:255'],
            'expected_fingerprint' => ['required', 'string', 'max:128'],
            'driver' => ['nullable', 'string', Rule::in(['fake', 'incus', 'lxd'])],
            'region' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'total_cpu_cores' => ['nullable', 'integer', 'min:1'],
            'total_ram_mb' => ['nullable', 'integer', 'min:1024'],
            'total_storage_gb' => ['nullable', 'integer', 'min:10'],
            'cpu_overcommit' => ['nullable', 'numeric', 'min:1', 'max:16'],
            'ram_overcommit' => ['nullable', 'numeric', 'min:1', 'max:4'],
            'disk_overcommit' => ['nullable', 'numeric', 'min:1', 'max:4'],
            'settings' => ['nullable', 'array'],
        ], ImageSelectionRules::rules()));

        $extraAttributes = $this->buildOnboardingAttributes($validated);

        $host = $this->trustService->addHostWithCertificate(
            Cast::string($validated['name'] ?? ''),
            Cast::string($validated['address'] ?? ''),
            Cast::string($validated['expected_fingerprint'] ?? ''),
            $extraAttributes
        );

        if (isset($validated['images']) && is_array($validated['images'])) {
            /** @var array<string, mixed> $imagesPayload */
            $imagesPayload = $validated['images'];
            $selection = ImageSelectionDTO::fromArray($imagesPayload);
            $this->imageAttachmentService->apply($host, $selection);
        }

        return response()->json([
            'message' => 'Compute node verified and added successfully.',
            'host' => new HostNodeResource($host),
        ], 201);
    }

    /**
     * Update an existing host node.
     */
    #[Put('admin/hosts/{host}', name: 'admin.hosts.update', middleware: ['auth:sanctum', 'active', 'admin'])]
    public function update(UpdateHostRequest $request, Host $host): JsonResponse
    {
        $updatedHost = $this->hostNodeService->update($host, $request->toDTO());

        return response()->json([
            'message' => 'Compute node updated successfully.',
            'host' => new HostNodeResource($updatedHost),
        ]);
    }

    /**
     * Preview impact of detaching images from an existing host node.
     */
    #[Post('admin/hosts/{host}/images/preview-detach', name: 'admin.hosts.images.preview_detach', middleware: ['auth:sanctum', 'active', 'admin'])]
    public function previewImageDetach(Request $request, Host $host): JsonResponse
    {
        /** @var array<string, mixed> $validated */
        $validated = $request->validate(ImageSelectionRules::forPreviewRequest());
        $imagesPayload = Cast::arrayOrNull($validated['images'] ?? null) ?? [];
        $selection = ImageSelectionDTO::fromArray($imagesPayload);
        $preview = $this->imageAttachmentService->preview($host, $selection);

        return response()->json($preview);
    }

    /**
     * Delete a host node.
     */
    #[Delete('admin/hosts/{host}', name: 'admin.hosts.destroy', middleware: ['auth:sanctum', 'active', 'admin'])]
    public function destroy(Request $request, Host $host): JsonResponse
    {
        $this->hostNodeService->delete($host);

        return response()->json([
            'message' => 'Compute node deleted successfully.',
        ]);
    }

    /**
     * Verify connection to a compute node before saving or with dynamic credentials.
     */
    #[Post('admin/hosts/verify-connection', name: 'admin.hosts.verify_connection_dynamic', middleware: ['auth:sanctum', 'active', 'admin'])]
    public function verifyDraftConnection(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'endpoint_url' => ['required', 'string', 'max:255'],
            'driver' => ['nullable', 'string', Rule::in(['fake', 'incus', 'lxd'])],
            'expected_fingerprint' => ['nullable', 'string', 'max:128'],
            'server_certificate' => ['nullable', 'string'],
            'token' => ['nullable', 'string'],
            'host_id' => ['nullable', 'integer', 'exists:hosts,id'],
        ]);

        if (isset($validated['host_id']) && is_numeric($validated['host_id'])) {
            $existingHost = Host::find((int) $validated['host_id']);
            if ($existingHost !== null) {
                $result = $this->connectionChecker->check($existingHost);

                return response()->json($result->toArray());
            }
        }

        $result = $this->connectionChecker->checkDynamic(
            endpointUrl: Cast::string($validated['endpoint_url'] ?? ''),
            expectedFingerprint: isset($validated['expected_fingerprint']) && is_string($validated['expected_fingerprint']) ? $validated['expected_fingerprint'] : null,
            serverCertificate: isset($validated['server_certificate']) && is_string($validated['server_certificate']) ? $validated['server_certificate'] : null,
            driver: isset($validated['driver']) && is_string($validated['driver']) ? $validated['driver'] : 'incus',
        );

        return response()->json($result->toArray());
    }

    /**
     * Re-trust an existing compute node using a new trust token.
     */
    #[Post('admin/hosts/{host}/retrust', name: 'admin.hosts.retrust', middleware: ['auth:sanctum', 'active', 'admin'])]
    public function retrust(Request $request, Host $host): JsonResponse
    {
        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'token' => ['required', 'string'],
        ]);

        $host = $this->trustService->retrustHostWithToken($host, (string) $validated['token']);

        return response()->json([
            'message' => 'Compute node re-trusted successfully.',
            'host' => new HostNodeResource($host),
        ]);
    }

    /**
     * Verify connection and cryptographic trust to an existing saved compute node.
     */
    #[Post('admin/hosts/{host}/verify-connection', name: 'admin.hosts.verify_connection', middleware: ['auth:sanctum', 'active', 'admin'])]
    public function verifyConnection(Host $host): JsonResponse
    {
        $result = $this->connectionChecker->check($host);

        return response()->json($result->toArray());
    }

    /**
     * Discover real network interfaces and storage pools on a compute node.
     */
    #[Get('admin/hosts/{host}/resources', name: 'admin.hosts.resources', middleware: ['auth:sanctum', 'active', 'admin'])]
    public function discoverResources(Host $host, TenantProjectProvisioner $provisioner): JsonResponse
    {
        return response()->json($provisioner->discoverHostResources($host));
    }

    /**
     * Synchronize live compute and storage capacity from the hypervisor.
     */
    #[Post('admin/hosts/{host}/sync-resources', name: 'admin.hosts.sync_resources', middleware: ['auth:sanctum', 'active', 'admin'])]
    public function syncResources(Host $host): JsonResponse
    {
        try {
            $syncedHost = $this->hostNodeService->syncHostResources($host, force: true);

            return response()->json([
                'message' => 'Compute node capacity synchronized successfully from hypervisor.',
                'host' => new HostNodeResource($syncedHost),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to synchronize compute node capacity: '.$e->getMessage(),
            ], 502);
        }
    }

    /**
     * Precompute committed VM usage across a collection of hosts to avoid N+1 queries in resources.
     *
     * @param  iterable<int, Host>  $hosts
     */
    private function attachCommittedUsage(iterable $hosts): void
    {
        $hostList = [];
        $hostIds = [];

        foreach ($hosts as $host) {
            $hostList[] = $host;
            $id = Cast::int($host->id);
            if ($id > 0) {
                $hostIds[] = $id;
            }
        }

        $hostIds = array_values(array_unique($hostIds));
        if ($hostIds === []) {
            return;
        }

        $guard = app(HostCapacityGuard::class);
        $usages = $guard->getCommittedUsageForHostIds($hostIds);

        foreach ($hostList as $host) {
            $host->setAttribute(
                'precomputed_committed_usage',
                $usages[$host->id] ?? ['cpu' => 0, 'ram' => 0, 'disk' => 0]
            );
        }
    }

    /**
     * Build extra host attributes from validated onboarding request input without injecting placeholder totals.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function buildOnboardingAttributes(array $validated, ?string $driver = null): array
    {
        $resolvedDriver = $driver ?? (isset($validated['driver']) && is_string($validated['driver']) ? $validated['driver'] : 'incus');

        $extraAttributes = [
            'driver' => $resolvedDriver,
            'region' => isset($validated['region']) && is_string($validated['region']) ? $validated['region'] : null,
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'auto_attach_images' => (bool) ($validated['auto_attach_images'] ?? true),
            'cpu_overcommit' => isset($validated['cpu_overcommit']) && is_numeric($validated['cpu_overcommit']) ? (float) $validated['cpu_overcommit'] : 1.0,
            'ram_overcommit' => isset($validated['ram_overcommit']) && is_numeric($validated['ram_overcommit']) ? (float) $validated['ram_overcommit'] : 1.0,
            'disk_overcommit' => isset($validated['disk_overcommit']) && is_numeric($validated['disk_overcommit']) ? (float) $validated['disk_overcommit'] : 1.0,
            'settings' => isset($validated['settings']) && is_array($validated['settings']) ? $validated['settings'] : null,
        ];

        if (isset($validated['total_cpu_cores']) && is_numeric($validated['total_cpu_cores'])) {
            $extraAttributes['total_cpu_cores'] = (int) $validated['total_cpu_cores'];
            $extraAttributes['capacity_source'] = 'manual';
        }
        if (isset($validated['total_ram_mb']) && is_numeric($validated['total_ram_mb'])) {
            $extraAttributes['total_ram_mb'] = (int) $validated['total_ram_mb'];
            $extraAttributes['capacity_source'] = 'manual';
        }
        if (isset($validated['total_storage_gb']) && is_numeric($validated['total_storage_gb'])) {
            $extraAttributes['total_storage_gb'] = (int) $validated['total_storage_gb'];
            $extraAttributes['capacity_source'] = 'manual';
        }

        return $extraAttributes;
    }
}
