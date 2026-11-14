<?php

declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditAction;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Audit\AuditOutcome;
use App\DTO\Host\CreateHostDTO;
use App\DTO\Host\UpdateHostDTO;
use App\Models\Host;
use App\Services\Hypervisor\HypervisorGateway;
use App\Services\Hypervisor\HypervisorManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Service managing Incus / LXD host cluster nodes and multi-region infrastructure.
 */
final class HostNodeService
{
    public function __construct(
        private ?AuditLogger $auditLogger = null,
    ) {
        $this->auditLogger ??= app(AuditLogger::class);
    }

    private function logger(): AuditLogger
    {
        return $this->auditLogger ??= app(AuditLogger::class);
    }

    /**
     * List active host nodes available for provisioning.
     *
     * @return Collection<int, Host>
     */
    public function listActive(): Collection
    {
        return Host::where('is_active', true)
            ->select(['id', 'name', 'region', 'endpoint_url', 'driver', 'is_active', 'total_cpu_cores', 'total_ram_mb', 'total_storage_gb'])
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
     * Paginate all host nodes with count relations for administrators.
     *
     * @return LengthAwarePaginator<int, Host>
     */
    public function paginateAll(int $perPage = 10): LengthAwarePaginator
    {
        return Host::withCount(['virtualMachines', 'ipPools'])
            ->orderBy('name')
            ->paginate($perPage);
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
            'capacity_source' => $hasManualCapacity ? 'manual' : 'unknown',
            'settings' => $dto->settings,
        ]);

        $parsedCreateHost = parse_url($host->endpoint_url, PHP_URL_HOST);

        $this->logger()->record(new AuditEntry(
            action: AuditAction::HostCreated,
            outcome: AuditOutcome::Success,
            subject: $host,
            tenantUserId: null,
            visibility: 'admin',
            context: [
                'name' => $host->name,
                'endpoint_host' => is_string($parsedCreateHost) && $parsedCreateHost !== '' ? $parsedCreateHost : $host->endpoint_url,
                'server_fingerprint' => $host->server_fingerprint,
            ],
        ));

        return $host->fresh(['ipPools', 'virtualMachines']) ?? $host;
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
            'settings' => $dto->settings ?? $host->settings,
        ];

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
            $attributes['capacity_source'] = 'manual';
        }

        $host->update($attributes);

        return $host->fresh(['ipPools', 'virtualMachines']) ?? $host;
    }

    /**
     * Delete a host node, ensuring no active virtual machines reside on it.
     */
    public function delete(Host $host): void
    {
        if ($host->virtualMachines()->count() > 0) {
            abort(422, 'Cannot delete compute node with active virtual machines deployed on it.');
        }

        $host->ipPools()->update(['host_id' => null]);

        $parsedDeleteHost = parse_url($host->endpoint_url, PHP_URL_HOST);

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
}
