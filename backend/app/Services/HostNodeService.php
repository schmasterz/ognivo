<?php

declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditAction;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Audit\AuditOutcome;
use App\Audit\AuditRedactor;
use App\DTO\Cast;
use App\DTO\Host\CreateHostDTO;
use App\DTO\Host\HostResourcesDTO;
use App\DTO\Host\UpdateHostDTO;
use App\Events\Host\HostMaintenanceEntered;
use App\Events\Host\HostMaintenanceExited;
use App\Events\Host\HostRegistered;
use App\Models\Host;
use App\Services\Hypervisor\Drivers\FakeHypervisorDriver;
use App\Services\Hypervisor\HypervisorGateway;
use App\Services\Hypervisor\HypervisorManager;
use App\States\Host\HostState;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Service managing Incus / LXD host cluster nodes and multi-region infrastructure.
 */
final class HostNodeService
{
    public function __construct(
        private ?AuditLogger $auditLogger = null,
        private ?ImageAttachmentService $imageAttachmentService = null,
    ) {
        $this->auditLogger ??= app(AuditLogger::class);
        $this->imageAttachmentService ??= app(ImageAttachmentService::class);
    }

    private function logger(): AuditLogger
    {
        return $this->auditLogger ??= app(AuditLogger::class);
    }

    private function imageAttachments(): ImageAttachmentService
    {
        return $this->imageAttachmentService ??= app(ImageAttachmentService::class);
    }

    /**
     * List active host nodes available for provisioning.
     *
     * @return Collection<int, Host>
     */
    public function listActive(): Collection
    {
        return Host::where('is_active', true)
            ->select([
                'id',
                'name',
                'region',
                'endpoint_url',
                'driver',
                'status',
                'is_active',
                'total_cpu_cores',
                'total_ram_mb',
                'total_storage_gb',
                'capacity_source',
                'cpu_overcommit',
                'ram_overcommit',
                'disk_overcommit',
            ])
            ->orderBy('name')
            ->get();
    }

    /**
     * List all host nodes with count relations for administrators.
     *
     * @return Collection<int, Host>
     */
    public function listAll(): Collection
    {
        return Host::withCount(['virtualMachines', 'ipPools'])
            ->orderBy('name')
            ->get();
    }

    /**
     * Paginate all host nodes with count relations for administrators, optionally filtered.
     *
     * @param  array{search?: string|null, status?: string|null, is_active?: bool|null, driver?: string|null}  $filters
     * @return LengthAwarePaginator<int, Host>
     */
    public function paginateAll(int $perPage = 10, array $filters = []): LengthAwarePaginator
    {
        /** @var Builder<Host> $query */
        $query = Host::withCount(['virtualMachines', 'ipPools']);

        if (isset($filters['search']) && $filters['search'] !== '') {
            $search = $filters['search'];
            $query->where(function (Builder $q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('region', 'like', "%{$search}%")
                    ->orWhere('endpoint_url', 'like', "%{$search}%")
                    ->orWhere('server_fingerprint', 'like', "%{$search}%");
            });
        }

        if (isset($filters['status']) && $filters['status'] !== '') {
            $status = $filters['status'];
            if ($status === 'needs_retrust') {
                $query->where('needs_retrust', true);
            } else {
                $query->where('status', $status);
            }
        }

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('is_active', $filters['is_active']);
        }

        if (isset($filters['driver']) && $filters['driver'] !== '') {
            $query->where('driver', $filters['driver']);
        }

        return $query->orderBy('name')->paginate($perPage);
    }

    /**
     * Create a new host node.
     */
    public function create(CreateHostDTO $dto): Host
    {
        if ($dto->driver === 'fake') {
            $allowFake = app()->environment(['local', 'testing']) || (bool) config('app.allow_fake_driver');
            if (! $allowFake) {
                throw ValidationException::withMessages([
                    'driver' => [__('messages.host.fake_driver_disabled')],
                ]);
            }
        }

        $pin = $dto->serverPublicKeyPin;
        if ($pin === null && $dto->serverCertificate !== null && trim($dto->serverCertificate) !== '') {
            $pin = HypervisorGateway::computePinFromCertificate($dto->serverCertificate);
        }

        $hasManualCapacity = $dto->totalCpuCores > 0 && $dto->totalRamMb > 0 && $dto->totalStorageGb > 0;

        if ($hasManualCapacity) {
            $candidateHost = new Host([
                'name' => $dto->name,
                'endpoint_url' => $dto->endpointUrl,
                'server_certificate' => $dto->serverCertificate,
                'server_fingerprint' => $dto->serverFingerprint,
                'server_public_key_pin' => $pin,
                'driver' => $dto->driver,
            ]);

            $this->validateManualCapacityAgainstPhysical(
                $candidateHost,
                $dto->totalCpuCores,
                $dto->totalRamMb,
                $dto->totalStorageGb,
            );
        }

        return DB::transaction(function () use ($dto, $pin, $hasManualCapacity): Host {
            /** @var Host $host */
            $host = Host::create([
                'name' => $dto->name,
                'region' => $dto->region,
                'endpoint_url' => $dto->endpointUrl,
                'server_certificate' => $dto->serverCertificate,
                'server_fingerprint' => $dto->serverFingerprint,
                'server_public_key_pin' => $pin,
                'driver' => $dto->driver,
                'token' => $dto->token,
                'is_active' => $dto->isActive,
                'total_cpu_cores' => $hasManualCapacity ? $dto->totalCpuCores : 0,
                'total_ram_mb' => $hasManualCapacity ? $dto->totalRamMb : 0,
                'total_storage_gb' => $hasManualCapacity ? $dto->totalStorageGb : 0,
                'cpu_overcommit' => $dto->cpuOvercommit,
                'ram_overcommit' => $dto->ramOvercommit,
                'disk_overcommit' => $dto->diskOvercommit,
                'capacity_source' => $hasManualCapacity ? 'manual' : 'unknown',
                'settings' => $dto->settings,
                'auto_attach_images' => $dto->autoAttachImages,
            ]);

            $parsedCreateHost = parse_url($host->endpoint_url, PHP_URL_HOST);

            /** @var array<string, mixed> $context */
            $context = [
                'name' => $host->name,
                'endpoint_host' => is_string($parsedCreateHost) && $parsedCreateHost !== '' ? $parsedCreateHost : $host->endpoint_url,
                'server_fingerprint' => $host->server_fingerprint,
                'auto_attach_images' => (bool) $host->auto_attach_images,
            ];

            if ($dto->images !== null) {
                $imgResult = $this->imageAttachments()->apply($host, $dto->images);
                $context['images'] = [
                    'mode' => $dto->images->mode,
                    'attached' => DB::table('host_image')->where('host_id', $host->id)->count(),
                    'detached' => $imgResult['detached'],
                ];
            }

            $this->logger()->record(new AuditEntry(
                action: AuditAction::HostCreated,
                outcome: AuditOutcome::Success,
                subject: $host,
                tenantUserId: null,
                visibility: 'admin',
                context: $context,
            ));

            $hostname = is_string($parsedCreateHost) && $parsedCreateHost !== '' ? $parsedCreateHost : $host->name;
            $ipAddress = filter_var($hostname, FILTER_VALIDATE_IP) !== false ? $hostname : '';

            event(new HostRegistered($host->id, $host->name, $hostname, $ipAddress));

            return $host->fresh(['ipPools', 'virtualMachines']) ?? $host;
        });
    }

    /**
     * Update an existing host node.
     */
    public function update(Host $host, UpdateHostDTO $dto): Host
    {
        $targetDriver = $dto->driver ?? $host->driver;
        if ($targetDriver === 'fake') {
            $allowFake = app()->environment(['local', 'testing']) || (bool) config('app.allow_fake_driver');
            if (! $allowFake) {
                throw ValidationException::withMessages([
                    'driver' => [__('messages.host.fake_driver_disabled')],
                ]);
            }
        }

        $oldAutoAttach = (bool) $host->auto_attach_images;
        $oldIsActive = $host->is_active;

        /** @var array<string, mixed> $attributes */
        $attributes = [
            'name' => $dto->name,
            'region' => $dto->region,
            'endpoint_url' => $dto->endpointUrl,
            'driver' => $targetDriver,
            'is_active' => $dto->isActive ?? $host->is_active,
            'total_cpu_cores' => $dto->totalCpuCores ?? $host->total_cpu_cores,
            'total_ram_mb' => $dto->totalRamMb ?? $host->total_ram_mb,
            'total_storage_gb' => $dto->totalStorageGb ?? $host->total_storage_gb,
            'cpu_overcommit' => $dto->cpuOvercommit ?? $host->cpu_overcommit,
            'ram_overcommit' => $dto->ramOvercommit ?? $host->ram_overcommit,
            'disk_overcommit' => $dto->diskOvercommit ?? $host->disk_overcommit,
            'settings' => $dto->settings ?? $host->settings,
            'auto_attach_images' => $dto->autoAttachImages ?? $host->auto_attach_images,
        ];

        $autoAttachChanged = (bool) $attributes['auto_attach_images'] !== $oldAutoAttach;

        if ($dto->hasTokenUpdate) {
            $attributes['token'] = $dto->token;
        }

        if ($dto->hasCertificateUpdate) {
            $attributes['server_certificate'] = $dto->serverCertificate;
            $pin = $dto->serverPublicKeyPin;
            if ($pin === null && $dto->serverCertificate !== null && trim($dto->serverCertificate) !== '') {
                $pin = HypervisorGateway::computePinFromCertificate($dto->serverCertificate);
            }
            $attributes['server_public_key_pin'] = $pin;
        } elseif ($dto->serverPublicKeyPin !== null) {
            $attributes['server_public_key_pin'] = $dto->serverPublicKeyPin;
        }

        if ($dto->serverFingerprint !== null) {
            $attributes['server_fingerprint'] = $dto->serverFingerprint;
        }

        $totalsEdited = ($dto->totalCpuCores !== null && $dto->totalCpuCores !== $host->total_cpu_cores)
            || ($dto->totalRamMb !== null && $dto->totalRamMb !== $host->total_ram_mb)
            || ($dto->totalStorageGb !== null && $dto->totalStorageGb !== $host->total_storage_gb);

        if ($totalsEdited) {
            $this->validateManualCapacityAgainstPhysical(
                $host,
                Cast::int($attributes['total_cpu_cores']),
                Cast::int($attributes['total_ram_mb']),
                Cast::int($attributes['total_storage_gb']),
            );

            $attributes['capacity_source'] = 'manual';
        }

        if ($dto->images === null) {
            return DB::transaction(function () use ($host, $attributes, $dto, $oldIsActive): Host {
                $host->update($attributes);
                $this->dispatchMaintenanceEventsIfChanged($host, $dto->isActive, $oldIsActive);

                return $host->fresh(['ipPools', 'virtualMachines']) ?? $host;
            });
        }

        return DB::transaction(function () use ($host, $attributes, $dto, $autoAttachChanged, $oldIsActive): Host {
            $host->fill($attributes);

            $diff = [];
            foreach ($host->getAuditableFields() as $field) {
                if ($host->isDirty($field)) {
                    if (in_array(strtolower($field), AuditRedactor::REDACTED_KEYS, true)) {
                        continue;
                    }

                    $oldVal = $host->getOriginal($field);
                    $newVal = $host->getAttribute($field);

                    if ($oldVal !== $newVal) {
                        $diff[$field] = [
                            'old' => $oldVal,
                            'new' => $newVal,
                        ];
                    }
                }
            }

            Host::withoutAuditing(fn () => $host->save());

            $imgResult = $this->imageAttachments()->apply($host, $dto->images);

            if (! empty($diff) || $imgResult['attached'] > 0 || $imgResult['detached'] > 0 || $autoAttachChanged) {
                $context = [];
                if (! empty($diff)) {
                    $context['diff'] = $diff;
                }
                $context['images'] = [
                    'attached' => $imgResult['attached'],
                    'detached' => $imgResult['detached'],
                    'auto_attach_changed' => $autoAttachChanged,
                ];

                $this->logger()->record(new AuditEntry(
                    action: AuditAction::HostUpdated,
                    outcome: AuditOutcome::Success,
                    subject: $host,
                    tenantUserId: null,
                    visibility: 'admin',
                    context: $context,
                ));
            }

            $this->dispatchMaintenanceEventsIfChanged($host, $dto->isActive, $oldIsActive);

            return $host->fresh(['ipPools', 'virtualMachines']) ?? $host;
        });
    }

    /**
     * Update a host node's operational status and emit a domain event if changed.
     */
    public function updateStatus(Host $host, string $newStatus): Host
    {
        $targetStateClass = HostState::resolveStateClass($newStatus);
        if ($targetStateClass === null || ! is_subclass_of($targetStateClass, HostState::class)) {
            throw ValidationException::withMessages([
                'status' => [sprintf('Invalid host status "%s".', $newStatus)],
            ]);
        }

        if (! $host->status->equals($targetStateClass)) {
            DB::transaction(function () use ($host, $targetStateClass): void {
                $host->status->transitionTo($targetStateClass);
            });
        }

        return $host;
    }

    /**
     * Dispatch host maintenance events when the active flag transitions.
     */
    private function dispatchMaintenanceEventsIfChanged(Host $host, ?bool $newIsActive, bool $oldIsActive): void
    {
        if ($newIsActive === null || $newIsActive === $oldIsActive) {
            return;
        }

        if ($newIsActive === false) {
            event(new HostMaintenanceEntered($host->id, 'Deactivated'));
        } else {
            event(new HostMaintenanceExited($host->id));
        }
    }

    /**
     * Delete a host node, ensuring no active virtual machines reside on it.
     */
    public function delete(Host $host): void
    {
        if ($host->virtualMachines()->whereNotIn('status', ['deleted'])->exists()) {
            abort(422, 'Cannot delete compute node with active virtual machines deployed on it.');
        }

        $parsedDeleteHost = parse_url($host->endpoint_url, PHP_URL_HOST);

        DB::transaction(function () use ($host, $parsedDeleteHost): void {
            $host->virtualMachines()->where('status', 'deleted')->update(['host_id' => null]);
            $host->ipPools()->delete();

            $this->logger()->record(new AuditEntry(
                action: AuditAction::HostDeleted,
                outcome: AuditOutcome::Success,
                subject: $host,
                tenantUserId: null,
                visibility: 'admin',
                context: [
                    'name' => $host->name,
                    'endpoint_host' => is_string($parsedDeleteHost) && $parsedDeleteHost !== '' ? $parsedDeleteHost : $host->endpoint_url,
                    'server_fingerprint' => $host->server_fingerprint,
                ],
            ));

            $host->delete();
        });
    }

    /**
     * Synchronize live compute and storage resources from the hypervisor.
     * Never overwrites manual capacity unless $force is true.
     */
    public function syncHostResources(Host $host, bool $force = false): Host
    {
        if (! $force && $host->capacity_source === 'manual') {
            return $host;
        }

        $manager = app(HypervisorManager::class);
        $driver = $manager->driverForHost($host);
        $resources = $driver->getHostResources($host);

        $host->update([
            'total_cpu_cores' => $resources->cpuCores,
            'total_ram_mb' => $resources->ramMb,
            'total_storage_gb' => $resources->storageGb,
            'capacity_source' => 'discovered',
            'capacity_synced_at' => now(),
        ]);

        $parsedEndpointHost = parse_url((string) $host->endpoint_url, PHP_URL_HOST);

        $this->logger()->record(new AuditEntry(
            action: AuditAction::HostResourcesSynced,
            outcome: AuditOutcome::Success,
            subject: $host,
            tenantUserId: null,
            visibility: 'admin',
            context: [
                'name' => $host->name,
                'endpoint_host' => is_string($parsedEndpointHost) && $parsedEndpointHost !== '' ? $parsedEndpointHost : (string) $host->endpoint_url,
                'total_cpu_cores' => $resources->cpuCores,
                'total_ram_mb' => $resources->ramMb,
                'total_storage_gb' => $resources->storageGb,
                'capacity_source' => 'discovered',
            ],
        ));

        return $host->fresh(['ipPools', 'virtualMachines']) ?? $host;
    }

    /**
     * Validate explicitly supplied manual capacity caps against the host's physical hardware resources.
     *
     * @throws ValidationException
     */
    private function validateManualCapacityAgainstPhysical(Host $host, int $reqCpu, int $reqRam, int $reqDisk): void
    {
        $physical = $this->resolvePhysicalCapacityBaseline($host);
        if ($physical === null) {
            return;
        }

        /** @var array<string, list<string>> $errors */
        $errors = [];

        if ($reqCpu > 0 && $physical->cpuCores > 0 && $reqCpu > $physical->cpuCores) {
            $errors['total_cpu_cores'] = [
                __('messages.host.capacity_exceeds_physical', [
                    'resource' => 'vCPU',
                    'max' => $physical->cpuCores.' cores',
                ]),
            ];
        }

        if ($reqRam > 0 && $physical->ramMb > 0 && $reqRam > $physical->ramMb) {
            $errors['total_ram_mb'] = [
                __('messages.host.capacity_exceeds_physical', [
                    'resource' => 'RAM',
                    'max' => $physical->ramMb.' MB',
                ]),
            ];
        }

        if ($reqDisk > 0 && $physical->storageGb > 0 && $reqDisk > $physical->storageGb) {
            $errors['total_storage_gb'] = [
                __('messages.host.capacity_exceeds_physical', [
                    'resource' => 'Storage',
                    'max' => $physical->storageGb.' GB',
                ]),
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function resolvePhysicalCapacityBaseline(Host $host): ?HostResourcesDTO
    {
        $fallbackDiscovered = $host->exists
            && $host->capacity_source === 'discovered'
            && $host->total_cpu_cores > 0
            && $host->total_ram_mb > 0
            && $host->total_storage_gb > 0
                ? new HostResourcesDTO(
                    cpuCores: $host->total_cpu_cores,
                    ramMb: $host->total_ram_mb,
                    storageGb: $host->total_storage_gb,
                    storagePool: 'default',
                )
                : null;

        try {
            $driver = app(HypervisorManager::class)->driverForHost($host);

            if ($driver instanceof FakeHypervisorDriver && $driver->hostResources === null && ! isset($driver->actionCallbacks['getHostResources'])) {
                return $fallbackDiscovered;
            }

            return $driver->getHostResources($host);
        } catch (Throwable) {
            return $fallbackDiscovered;
        }
    }
}
