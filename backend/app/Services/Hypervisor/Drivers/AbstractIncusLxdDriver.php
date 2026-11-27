<?php

declare(strict_types=1);

namespace App\Services\Hypervisor\Drivers;

use App\DTO\Host\HostResourcesDTO;
use App\DTO\Hypervisor\RawInstanceMetricsDTO;
use App\Enums\Hypervisor\OperationState;
use App\Enums\Hypervisor\ResourceUpdateStatus;
use App\Exceptions\DriverUnavailableException;
use App\Exceptions\FingerprintMismatchException;
use App\Models\Host;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\Hypervisor\Contracts\HypervisorDriverInterface;
use App\Services\Hypervisor\HypervisorGateway;
use App\Services\Hypervisor\InstanceConfigPatchBuilder;
use App\Services\Hypervisor\InstanceSpecBuilder;
use App\Services\Hypervisor\OpenMetricsParser;
use App\Services\Hypervisor\ResourceCapabilityMatrix;
use App\Services\Hypervisor\TenantProjectProvisioner;
use App\Services\PanelCertificateService;
use App\Support\Cast;
use App\ValueObjects\Hypervisor\InstanceResourceSpec;
use App\ValueObjects\Hypervisor\OperationOutcome;
use App\ValueObjects\Hypervisor\OperationStatus;
use App\ValueObjects\Hypervisor\ResourceUpdateAssessment;
use App\ValueObjects\Hypervisor\ResourceUpdateResult;
use DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Override;
use RuntimeException;
use Throwable;

/**
 * Base REST API hypervisor driver for Incus and LXD communicating over HTTPS with mTLS.
 */
abstract class AbstractIncusLxdDriver implements HypervisorDriverInterface
{
    /**
     * Most recent error message reported by the hypervisor REST API or async operation.
     */
    protected ?string $lastError = null;

    public function __construct(
        protected readonly ?Host $host = null,
        protected readonly ?PanelCertificateService $certService = null,
        protected readonly ?HypervisorGateway $gateway = null,
        protected readonly ?TenantProjectProvisioner $projectProvisioner = null,
        protected readonly ?InstanceSpecBuilder $instanceSpecBuilder = null,
        protected readonly ?InstanceConfigPatchBuilder $patchBuilder = null,
        protected readonly ?ResourceCapabilityMatrix $capabilityMatrix = null,
    ) {}

    /**
     * Get the most recent error message reported by the hypervisor.
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Get the specific hypervisor driver identifier ('incus' or 'lxd').
     */
    abstract public function getDriverName(): string;

    /**
     * Build an authenticated HTTP client for the target hypervisor host.
     */
    protected function client(): PendingRequest
    {
        if ($this->host === null) {
            throw new RuntimeException(sprintf('No host assigned to %s driver.', $this->getDriverName()));
        }

        return $this->clientForHost($this->host);
    }

    /**
     * Build an authenticated HTTP client for a specific hypervisor host.
     */
    protected function clientForHost(Host $host): PendingRequest
    {
        $service = $this->certService ?? app(PanelCertificateService::class);
        $clientCert = $service->getActiveCertificate() ?? $service->getRetiringCertificate();
        $gateway = $this->gateway ?? app(HypervisorGateway::class);

        return $gateway->client($host, $clientCert);
    }

    #[Override]
    public function getHostResources(Host $host): HostResourcesDTO
    {
        $client = $this->clientForHost($host);

        /** @var Response $res */
        $res = (clone $client)->get('/1.0/resources');
        if (! $res->successful()) {
            throw new RuntimeException(sprintf('Failed to retrieve host resources from %s: %s', $host->endpoint_url, $res->body()));
        }

        /** @var array<string, mixed> $resData */
        $resData = $res->json('metadata') ?? [];
        $cpuData = Cast::arrayOrNull($resData['cpu'] ?? null) ?? [];
        $cpuTotal = Cast::int($cpuData['total'] ?? 0);
        $memData = Cast::arrayOrNull($resData['memory'] ?? null) ?? [];
        $memBytes = Cast::int($memData['total'] ?? 0);
        $ramMb = intdiv($memBytes, 1024 * 1024);

        $configuredPool = Cast::string(config('provisioning.storage_pool', ''));
        if ($configuredPool !== '') {
            $poolName = $configuredPool;
        } else {
            $provisioner = $this->projectProvisioner ?? app(TenantProjectProvisioner::class);
            $poolName = $provisioner->discoverStoragePool($client);
        }

        /** @var Response $poolRes */
        $poolRes = (clone $client)->get(sprintf('/1.0/storage-pools/%s/resources', urlencode($poolName)));
        if (! $poolRes->successful()) {
            throw new RuntimeException(sprintf('Failed to retrieve storage pool resources for pool [%s] from %s: %s', $poolName, $host->endpoint_url, $poolRes->body()));
        }

        /** @var array<string, mixed> $poolData */
        $poolData = $poolRes->json('metadata') ?? [];
        $spaceData = Cast::arrayOrNull($poolData['space'] ?? null) ?? [];
        $spaceBytes = Cast::int($spaceData['total'] ?? 0);
        $storageGb = intdiv($spaceBytes, 1024 * 1024 * 1024);

        return new HostResourcesDTO(
            cpuCores: $cpuTotal,
            ramMb: $ramMb,
            storageGb: $storageGb,
            storagePool: $poolName,
        );
    }

    /**
     * @return array<string, RawInstanceMetricsDTO>
     */
    #[Override]
    public function getHostInstanceMetrics(Host $host, int $timeoutSeconds = 10): array
    {
        $timeout = max(1, min(10, $timeoutSeconds));
        $service = $this->certService ?? app(PanelCertificateService::class);
        $clientCert = $service->getActiveCertificate() ?? $service->getRetiringCertificate();
        $gateway = $this->gateway ?? app(HypervisorGateway::class);

        $client = $gateway->client(
            host: $host,
            certificate: $clientCert,
            timeout: $timeout,
            connectTimeout: min(5, $timeout),
        );

        /** @var Response $response */
        $response = $client->get('/1.0/metrics');
        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'Failed to retrieve metrics from %s (HTTP %d): %s',
                $host->endpoint_url,
                $response->status(),
                substr(trim($response->body()), 0, 200)
            ));
        }

        return app(OpenMetricsParser::class)->parse($response->body());
    }

    /**
     * Handle hypervisor operation response, waiting for asynchronous operations to finish.
     *
     * @param  list<string>  $acceptableErrors  Error substrings that should be treated as idempotent success
     */
    protected function handleOperationResponse(Response $response, ?string $project = null, array $acceptableErrors = []): bool
    {
        $outcome = $this->executeOperation($response, $project, $acceptableErrors, 120);

        if ($outcome->isSucceeded()) {
            $this->lastError = null;

            return true;
        }

        if ($outcome->isRunning()) {
            $this->lastError = $outcome->errorMessage ?? 'Operation failed';
            Log::error(sprintf('Hypervisor operation timed out while waiting for completion: %s', $outcome->operationId ?? 'unknown'));

            return false;
        }

        $this->lastError = $outcome->errorMessage;

        return false;
    }

    /**
     * Execute and wait for a hypervisor operation response, returning a structured outcome.
     *
     * @param  list<string>  $acceptableErrors  Error substrings that should be treated as idempotent success
     */
    protected function executeOperation(
        Response $response,
        ?string $project = null,
        array $acceptableErrors = [],
        ?int $waitSeconds = null,
    ): OperationOutcome {
        $this->lastError = null;
        $timeout = max(0, $waitSeconds ?? 120);

        if (! $response->successful()) {
            $err = $response->json('error') ?? $response->json('metadata.err');
            $errStr = is_string($err) && $err !== ''
                ? $err
                : sprintf('HTTP %d: %s', $response->status(), substr(trim($response->body()), 0, 300));

            if ($this->matchesAcceptableError($errStr, $acceptableErrors)) {
                Log::info(sprintf('Hypervisor synchronous request idempotent outcome (project %s): %s', $project ?? 'default', $errStr));

                return OperationOutcome::succeeded();
            }

            $this->lastError = $errStr;
            Log::error(sprintf('Hypervisor synchronous request failed (HTTP %d, project %s): %s', $response->status(), $project ?? 'default', $errStr));

            return OperationOutcome::failed($errStr);
        }

        /** @var mixed $rawData */
        $rawData = $response->json();
        /** @var array<string, mixed> $data */
        $data = is_array($rawData) ? $rawData : [];
        $type = $data['type'] ?? 'sync';

        // Incus and LXD execute instance operations asynchronously (HTTP 202)
        if ($type === 'async' && isset($data['operation']) && is_string($data['operation'])) {
            $operationUrl = $data['operation'];
            $operationId = $this->extractOperationId($operationUrl, $data);
            $waitUrl = sprintf('%s/wait', $operationUrl);
            /** @var array<string, mixed> $waitQuery */
            $waitQuery = ['timeout' => $timeout];
            if ($project !== null && $project !== '') {
                $waitQuery['project'] = $project;
            }

            try {
                /** @var Response $waitResponse */
                $waitResponse = $this->client()->get($waitUrl, $waitQuery);

                if ($waitResponse->status() === 408 || $waitResponse->status() === 504) {
                    return OperationOutcome::running($operationId);
                }

                if (! $waitResponse->successful()) {
                    $waitErr = $waitResponse->json('error') ?? $waitResponse->json('metadata.err');
                    $errStr = is_string($waitErr) && $waitErr !== ''
                        ? $waitErr
                        : sprintf('HTTP %d while waiting for operation', $waitResponse->status());
                    $this->lastError = $errStr;
                    Log::error(sprintf('Hypervisor operation wait failed: %s', $errStr));

                    return OperationOutcome::failed($errStr, $operationId);
                }

                /** @var mixed $rawWaitData */
                $rawWaitData = $waitResponse->json();
                /** @var array<string, mixed> $waitData */
                $waitData = is_array($rawWaitData) ? $rawWaitData : [];
                /** @var array<string, mixed> $metadata */
                $metadata = isset($waitData['metadata']) && is_array($waitData['metadata']) ? $waitData['metadata'] : [];
                if ($operationId === null && isset($metadata['id']) && is_string($metadata['id']) && $metadata['id'] !== '') {
                    $operationId = $metadata['id'];
                }

                $status = isset($metadata['status']) && is_string($metadata['status'])
                    ? $metadata['status']
                    : (isset($waitData['status']) && is_string($waitData['status']) ? $waitData['status'] : '');

                $isRunningCode = ! isset($metadata['status']) && in_array($metadata['status_code'] ?? null, [100, 103], true);
                if (in_array(strtolower($status), ['running', 'pending'], true) || $isRunningCode) {
                    return OperationOutcome::running($operationId);
                }

                if ($status !== 'Success') {
                    $err = $metadata['err'] ?? ($waitData['error'] ?? 'Operation failed');
                    $encoded = is_scalar($err) || is_array($err) ? json_encode($err) : false;
                    $errStr = is_string($err) && $err !== '' ? $err : ($encoded !== false ? $encoded : 'Operation failed');

                    if ($this->matchesAcceptableError($errStr, $acceptableErrors)) {
                        Log::info(sprintf('Hypervisor operation idempotent outcome (project %s): %s', $project ?? 'default', $errStr));

                        return OperationOutcome::succeeded($operationId);
                    }

                    $this->lastError = $errStr;
                    Log::error(sprintf('Hypervisor operation failed: %s', $errStr));

                    return OperationOutcome::failed($errStr, $operationId);
                }

                return OperationOutcome::succeeded($operationId);
            } catch (FingerprintMismatchException $e) {
                throw $e;
            } catch (ConnectionException $e) {
                if (stripos($e->getMessage(), 'timed out') !== false || stripos($e->getMessage(), 'timeout') !== false) {
                    Log::info(sprintf('Hypervisor operation wait timed out (project %s, operation %s): %s', $project ?? 'default', $operationId ?? 'unknown', $e->getMessage()));

                    return OperationOutcome::running($operationId);
                }

                $this->lastError = $e->getMessage();
                Log::error(sprintf('Exception while waiting for hypervisor operation: %s', $e->getMessage()));

                return OperationOutcome::failed($e->getMessage(), $operationId);
            } catch (Throwable $e) {
                $this->lastError = $e->getMessage();
                Log::error(sprintf('Exception while waiting for hypervisor operation: %s', $e->getMessage()));

                return OperationOutcome::failed($e->getMessage(), $operationId);
            }
        }

        return OperationOutcome::succeeded();
    }

    /**
     * Check whether an error string matches any idempotent acceptable error substring.
     *
     * @param  list<string>  $acceptableErrors
     */
    protected function matchesAcceptableError(string $errorMessage, array $acceptableErrors): bool
    {
        foreach ($acceptableErrors as $acceptable) {
            if (stripos($errorMessage, $acceptable) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract the operation identifier from an async operation URL or response payload.
     *
     * @param  array<string, mixed>  $data
     */
    protected function extractOperationId(string $operationUrl, array $data): ?string
    {
        $path = parse_url($operationUrl, PHP_URL_PATH);
        $id = basename(is_string($path) && $path !== '' ? $path : $operationUrl);
        if ($id !== '' && $id !== '.' && $id !== '/') {
            return $id;
        }

        $metadata = isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : [];
        if (isset($metadata['id']) && is_string($metadata['id']) && $metadata['id'] !== '') {
            return $metadata['id'];
        }

        return null;
    }

    /**
     * Ensure the tenant isolation project exists on the hypervisor.
     */
    protected function ensureProjectExists(string $project, ?VirtualMachine $vm = null): void
    {
        if ($project === 'default' || $project === '') {
            return;
        }

        try {
            $provisioner = $this->projectProvisioner ?? app(TenantProjectProvisioner::class);
            /** @var User|null $user */
            $user = $vm !== null ? ($vm->user ?? User::find($vm->user_id)) : null;
            $quota = $user?->quota;

            $provisioner->ensureProject($this->client(), $project, $quota);
        } catch (FingerprintMismatchException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::warning(sprintf('Notice during ensureProjectExists for %s: %s', $project, $e->getMessage()));
        }
    }

    #[Override]
    public function createInstance(VirtualMachine $vm): bool
    {
        $this->ensureProjectExists($vm->incus_project, $vm);

        $provisioner = $this->projectProvisioner ?? app(TenantProjectProvisioner::class);
        $client = $this->client();

        $storagePool = $provisioner->discoverStoragePool($client);
        $availableNetworks = $provisioner->listNetworkNames($client);

        $builder = $this->instanceSpecBuilder ?? app(InstanceSpecBuilder::class);
        $payload = $builder->build($vm, null, $storagePool, $availableNetworks);

        /** @var Response $response */
        $response = (clone $client)
            ->withQueryParameters(['project' => $vm->incus_project])
            ->post('/1.0/instances', $payload);

        $created = $this->handleOperationResponse($response, $vm->incus_project);

        // If creation failed because a /cloud image variant does not exist on the remote server,
        // automatically fall back to the base alias (without /cloud) and retry once.
        if (
            ! $created
            && $this->lastError !== null
            && isset($payload['source'])
            && is_array($payload['source'])
            && isset($payload['source']['alias'])
            && is_string($payload['source']['alias'])
            && str_ends_with($payload['source']['alias'], '/cloud')
            && (str_contains(strtolower($this->lastError), 'image') || str_contains(strtolower($this->lastError), 'not found'))
        ) {
            $fallbackAlias = substr($payload['source']['alias'], 0, -6);
            Log::info(sprintf(
                'Retrying instance creation for VM #%d (%s) with fallback image alias "%s" after "%s" failed.',
                $vm->id,
                $vm->name,
                $fallbackAlias,
                $payload['source']['alias']
            ));
            $payload['source']['alias'] = $fallbackAlias;

            /** @var Response $retryResponse */
            $retryResponse = (clone $client)
                ->withQueryParameters(['project' => $vm->incus_project])
                ->post('/1.0/instances', $payload);

            return $this->handleOperationResponse($retryResponse, $vm->incus_project);
        }

        return $created;
    }

    #[Override]
    public function startInstance(VirtualMachine $vm): bool
    {
        /** @var Response $response */
        $response = $this->client()
            ->withQueryParameters(['project' => $vm->incus_project])
            ->put(sprintf('/1.0/instances/%s/state', $vm->name), [
                'action' => 'start',
                'timeout' => 30,
            ]);

        return $this->handleOperationResponse($response, $vm->incus_project, ['already running']);
    }

    #[Override]
    public function stopInstance(VirtualMachine $vm, bool $force = false): bool
    {
        /** @var Response $response */
        $response = $this->client()
            ->withQueryParameters(['project' => $vm->incus_project])
            ->put(sprintf('/1.0/instances/%s/state', $vm->name), [
                'action' => 'stop',
                'force' => $force,
                'timeout' => 30,
            ]);

        return $this->handleOperationResponse($response, $vm->incus_project, ['already stopped', 'is not running', 'not running']);
    }

    #[Override]
    public function suspendInstance(VirtualMachine $vm): bool
    {
        /** @var Response $response */
        $response = $this->client()
            ->withQueryParameters(['project' => $vm->incus_project])
            ->put(sprintf('/1.0/instances/%s/state', $vm->name), [
                'action' => 'freeze',
                'timeout' => 30,
            ]);

        return $this->handleOperationResponse($response, $vm->incus_project, ['already frozen', 'already suspended']);
    }

    #[Override]
    public function deleteInstance(VirtualMachine $vm): bool
    {
        /** @var Response $response */
        $response = $this->client()
            ->withQueryParameters(['project' => $vm->incus_project])
            ->delete(sprintf('/1.0/instances/%s', $vm->name));

        // If instance does not exist on hypervisor, treat as cleanly removed (idempotent delete)
        if ($response->status() === 404) {
            return true;
        }

        // If the instance is running, attempt to force stop it first and retry deletion
        if ($response->status() === 400 && str_contains(strtolower((string) $response->body()), 'running')) {
            $this->stopInstance($vm, true);
            $response = $this->client()
                ->withQueryParameters(['project' => $vm->incus_project])
                ->delete(sprintf('/1.0/instances/%s', $vm->name));

            if ($response->status() === 404) {
                return true;
            }
        }

        return $this->handleOperationResponse($response, $vm->incus_project);
    }

    #[Override]
    public function getInstanceStatus(VirtualMachine $vm): string
    {
        /** @var Response $response */
        $response = $this->client()
            ->withQueryParameters(['project' => $vm->incus_project])
            ->get(sprintf('/1.0/instances/%s/state', $vm->name));

        if (! $response->successful()) {
            return 'Unknown';
        }

        /** @var array<string, mixed> $data */
        $data = $response->json() ?? [];
        /** @var array<string, mixed> $metadata */
        $metadata = isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : [];

        return isset($metadata['status']) && is_string($metadata['status']) ? $metadata['status'] : 'Unknown';
    }

    #[Override]
    public function getInstanceState(VirtualMachine $vm): array
    {
        /** @var Response $response */
        $response = $this->client()
            ->withQueryParameters(['project' => $vm->incus_project])
            ->get(sprintf('/1.0/instances/%s/state', $vm->name));

        if (! $response->successful()) {
            return [];
        }

        /** @var array<string, mixed> $data */
        $data = $response->json() ?? [];

        /** @var array<string, mixed> $metadata */
        return isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : [];
    }

    #[Override]
    public function getInstanceConsoleLog(VirtualMachine $vm): string
    {
        try {
            /** @var Response $response */
            $response = $this->client()
                ->withQueryParameters(['project' => $vm->incus_project, 'buffer' => 'true'])
                ->get(sprintf('/1.0/instances/%s/console', $vm->name));

            if (! $response->successful()) {
                if ($response->status() === 404) {
                    $err = Cast::string($response->json('error') ?? $response->body());
                    if (str_contains($err, 'no such file or directory') || str_contains($err, 'not found')) {
                        if ($vm->instance_type === 'virtual-machine') {
                            return Cast::string(__('messages.vm.console_vm_serial_notice'));
                        }

                        return 'Console buffer is empty.';
                    }
                }

                if ($vm->instance_type === 'virtual-machine') {
                    return Cast::string(__('messages.vm.console_vm_serial_notice'));
                }

                return "Unable to fetch console log from hypervisor. Instance status: {$this->getInstanceStatus($vm)}.";
            }

            $body = $response->body();
            if (strlen($body) > 51200) {
                $body = substr($body, -51200);
            }

            if ($body !== '') {
                return $body;
            }

            return $vm->instance_type === 'virtual-machine'
                ? Cast::string(__('messages.vm.console_vm_serial_notice'))
                : 'Console buffer is empty.';
        } catch (Throwable $e) {
            return "Error retrieving console log: {$e->getMessage()}";
        }
    }

    /**
     * List projects on the hypervisor host.
     *
     * @return list<string>
     */
    #[Override]
    public function listProjects(): array
    {
        $response = $this->client()->get('/1.0/projects');
        if (! $response->successful()) {
            return ['default'];
        }

        /** @var array<string, mixed> $data */
        $data = $response->json() ?? [];
        /** @var list<string> $metadata */
        $metadata = isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : [];

        $projects = [];
        foreach ($metadata as $item) {
            $projects[] = basename((string) $item);
        }

        return ! empty($projects) ? array_values(array_unique($projects)) : ['default'];
    }

    /**
     * List all instances on the hypervisor for a given project (or all projects).
     *
     * @return list<array{name: string, project: string, status: string}>
     */
    #[Override]
    public function listInstances(?string $project = null): array
    {
        $query = ['recursion' => 1];
        if ($project !== null) {
            $query['project'] = $project;
        }

        $response = $this->client()
            ->withQueryParameters($query)
            ->get('/1.0/instances');

        if (! $response->successful()) {
            return [];
        }

        /** @var array<string, mixed> $data */
        $data = $response->json() ?? [];
        /** @var list<array<string, mixed>> $metadata */
        $metadata = isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : [];

        $instances = [];
        foreach ($metadata as $item) {
            if (is_array($item)) {
                $instances[] = [
                    'name' => isset($item['name']) ? (string) $item['name'] : '',
                    'project' => isset($item['project']) ? (string) $item['project'] : ($project ?? 'default'),
                    'status' => isset($item['status']) ? (string) $item['status'] : 'Unknown',
                ];
            }
        }

        return $instances;
    }

    /**
     * Delete the specified project on the hypervisor host.
     */
    #[Override]
    public function deleteProject(string $project): bool
    {
        if ($project === 'default' || $project === '') {
            return false;
        }

        $response = $this->client()->delete(sprintf('/1.0/projects/%s', $project));
        if ($response->status() === 404) {
            return true;
        }

        return $response->successful();
    }

    #[Override]
    public function createSnapshot(VirtualMachine $vm, string $name): bool
    {
        /** @var Response $response */
        $response = $this->client()
            ->withQueryParameters(['project' => $vm->incus_project])
            ->post(sprintf('/1.0/instances/%s/snapshots', rawurlencode($vm->name)), [
                'name' => $name,
                'stateful' => false,
            ]);

        return $this->handleOperationResponse($response, $vm->incus_project);
    }

    /**
     * List all snapshots for the specified instance.
     *
     * @return list<array{name: string, created_at: string, size: int|null}>
     */
    #[Override]
    public function listSnapshots(VirtualMachine $vm): array
    {
        /** @var Response $response */
        $response = $this->client()
            ->withQueryParameters([
                'project' => $vm->incus_project,
                'recursion' => 1,
            ])
            ->get(sprintf('/1.0/instances/%s/snapshots', rawurlencode($vm->name)));

        if (! $response->successful()) {
            return [];
        }

        /** @var array<string, mixed> $data */
        $data = $response->json() ?? [];
        /** @var list<mixed> $metadata */
        $metadata = isset($data['metadata']) && is_array($data['metadata']) ? array_values($data['metadata']) : [];

        $snapshots = [];
        foreach ($metadata as $item) {
            if (is_array($item)) {
                $rawName = isset($item['name']) && is_scalar($item['name']) ? (string) $item['name'] : '';
                if (str_contains($rawName, '/')) {
                    $rawName = basename($rawName);
                }

                $size = null;
                if (isset($item['size']) && is_numeric($item['size'])) {
                    $parsedSize = (int) $item['size'];
                    if ($parsedSize >= 0) {
                        $size = $parsedSize;
                    }
                }

                $snapshots[] = [
                    'name' => $rawName,
                    'created_at' => isset($item['created_at']) && is_scalar($item['created_at']) ? (string) $item['created_at'] : '',
                    'size' => $size,
                ];
            } elseif (is_string($item)) {
                $snapshots[] = [
                    'name' => basename($item),
                    'created_at' => '',
                    'size' => null,
                ];
            }
        }

        return $snapshots;
    }

    #[Override]
    public function restoreSnapshot(VirtualMachine $vm, string $name): bool
    {
        /** @var Response $response */
        $response = $this->client()
            ->withQueryParameters(['project' => $vm->incus_project])
            ->put(sprintf('/1.0/instances/%s', rawurlencode($vm->name)), [
                'restore' => $name,
            ]);

        return $this->handleOperationResponse($response, $vm->incus_project);
    }

    #[Override]
    public function deleteSnapshot(VirtualMachine $vm, string $name): bool
    {
        /** @var Response $response */
        $response = $this->client()
            ->withQueryParameters(['project' => $vm->incus_project])
            ->delete(sprintf('/1.0/instances/%s/snapshots/%s', rawurlencode($vm->name), rawurlencode($name)));

        if ($response->status() === 404) {
            return true;
        }

        return $this->handleOperationResponse($response, $vm->incus_project, ['not found']);
    }

    #[Override]
    public function createConsoleSession(VirtualMachine $vm, string $type = 'console', int $width = 80, int $height = 24): array
    {
        if ($vm->instance_type === 'container' && $type === 'vga') {
            throw new InvalidArgumentException('Containers do not support graphical VGA console.');
        }

        $width = max(20, min(500, $width));
        $height = max(5, min(200, $height));

        /** @var Response $response */
        $response = $this->client()
            ->withQueryParameters([
                'project' => $vm->incus_project,
                'wait-for-websocket' => 'true',
            ])
            ->post(sprintf('/1.0/instances/%s/console', rawurlencode($vm->name)), [
                'type' => $type,
                'width' => $width,
                'height' => $height,
                'wait-for-websocket' => true,
            ]);

        if (! $response->successful()) {
            $err = $response->json('error');
            $msg = is_string($err) && $err !== '' ? $err : $response->body();
            throw new RuntimeException(sprintf('Failed to create console session on hypervisor: %s', $msg !== '' ? $msg : 'Unknown error'));
        }

        return $this->parseWebsocketOperationResponse($response, $type, 'console');
    }

    #[Override]
    public function createExecSession(VirtualMachine $vm, int $width = 80, int $height = 24): array
    {
        $width = max(20, min(500, $width));
        $height = max(5, min(200, $height));

        $client = $this->client();
        if ($vm->instance_type !== 'container') {
            $client = $client->timeout(5)->connectTimeout(3);
        }

        /** @var Response $response */
        $response = $client
            ->withQueryParameters(['project' => $vm->incus_project])
            ->post(sprintf('/1.0/instances/%s/exec', rawurlencode($vm->name)), [
                'command' => [
                    '/bin/sh',
                    '-c',
                    'if [ -x /bin/bash ]; then exec /bin/bash -l; elif [ -x /usr/bin/bash ]; then exec /usr/bin/bash -l; elif [ -x /bin/sh ]; then exec /bin/sh -l; else exit 127; fi',
                ],
                'cwd' => '/root',
                'environment' => [
                    'TERM' => 'xterm-256color',
                    'HOME' => '/root',
                    'USER' => 'root',
                ],
                'interactive' => true,
                'wait-for-websocket' => true,
                'width' => $width,
                'height' => $height,
                'user' => 0,
                'group' => 0,
            ]);

        if (! $response->successful()) {
            $err = $response->json('error');
            $msg = is_string($err) && $err !== '' ? $err : $response->body();
            throw new RuntimeException(sprintf('Failed to create exec session on hypervisor: %s', $msg !== '' ? $msg : 'Unknown error'));
        }

        return $this->parseWebsocketOperationResponse($response, 'console', 'exec');
    }

    #[Override]
    public function cancelOperation(VirtualMachine $vm, string $operationId): bool
    {
        $operationId = trim($operationId);
        if ($operationId === '') {
            return false;
        }

        try {
            /** @var Response $response */
            $response = $this->client()
                ->timeout(5)
                ->withQueryParameters(['project' => $vm->incus_project])
                ->delete(sprintf('/1.0/operations/%s', rawurlencode($operationId)));

            return $response->successful() || $response->status() === 404;
        } catch (FingerprintMismatchException $e) {
            throw $e;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Parse the websocket operation response from the hypervisor.
     *
     * @return array{operation_id: string, websocket_path: string, control_path: string|null, type: string, driver: string}
     */
    protected function parseWebsocketOperationResponse(Response $response, string $type, string $driver): array
    {
        /** @var mixed $rawJson */
        $rawJson = $response->json();
        $data = is_array($rawJson) ? $rawJson : [];
        $operationUrl = isset($data['operation']) && is_string($data['operation']) ? $data['operation'] : '';
        $operationId = basename($operationUrl);

        /** @var mixed $rawMeta */
        $rawMeta = $data['metadata'] ?? null;
        $metadata = is_array($rawMeta) ? $rawMeta : [];
        if ($operationId === '' && isset($metadata['id']) && is_scalar($metadata['id'])) {
            $operationId = (string) $metadata['id'];
        }

        /** @var mixed $rawNested */
        $rawNested = $metadata['metadata'] ?? null;
        $nestedMeta = is_array($rawNested) ? $rawNested : [];
        /** @var mixed $rawFds */
        $rawFds = $nestedMeta['fds'] ?? ($metadata['fds'] ?? null);
        $fds = is_array($rawFds) ? $rawFds : [];

        $dataSecret = isset($fds['0']) && is_string($fds['0']) ? $fds['0'] : '';
        $controlSecret = isset($fds['control']) && is_string($fds['control']) ? $fds['control'] : null;

        if ($dataSecret === '') {
            throw new RuntimeException('Hypervisor did not return file descriptor secrets for console websocket.');
        }

        $websocketPath = sprintf('/1.0/operations/%s/websocket?secret=%s', $operationId, $dataSecret);
        $controlPath = $controlSecret !== null && $controlSecret !== ''
            ? sprintf('/1.0/operations/%s/websocket?secret=%s', $operationId, $controlSecret)
            : null;

        return [
            'operation_id' => $operationId,
            'websocket_path' => $websocketPath,
            'control_path' => $controlPath,
            'type' => $type,
            'driver' => $driver,
        ];
    }

    #[Override]
    public function getInstanceResourceSpec(VirtualMachine $vm): InstanceResourceSpec
    {
        $fetched = $this->fetchInstanceWithEtag($vm);

        return $this->patchBuilderService()->parseSpec($fetched['data']);
    }

    #[Override]
    public function assessResourceUpdate(VirtualMachine $vm, InstanceResourceSpec $target): ResourceUpdateAssessment
    {
        $fetched = $this->fetchInstanceWithEtag($vm);
        $instanceData = $fetched['data'];
        $observed = $this->patchBuilderService()->parseSpec($instanceData);

        $vmState = Cast::string($instanceData['status'] ?? '');
        if ($vmState === '') {
            $vmState = $this->getInstanceStatus($vm);
        }

        $storageDriver = null;
        if ($observed->instanceType === 'container' && $target->diskGb > $observed->diskGb) {
            $storageDriver = $this->resolveStorageDriver($instanceData);
        }

        $nicType = $this->extractLocalNicType($instanceData);

        return $this->capabilityMatrixService()->assess(
            observed: $observed,
            target: $target,
            vmState: $vmState,
            storageDriver: $storageDriver,
            nicType: $nicType,
        );
    }

    #[Override]
    public function applyResourceUpdate(VirtualMachine $vm, InstanceResourceSpec $target): ResourceUpdateResult
    {
        try {
            $fetched = $this->fetchInstanceWithEtag($vm);
        } catch (FingerprintMismatchException $e) {
            throw $e;
        } catch (DriverUnavailableException $e) {
            return new ResourceUpdateResult(
                status: ResourceUpdateStatus::Failed,
                errorCode: 'DRIVER_UNAVAILABLE',
                safeMessage: $this->sanitizeSafeMessage($e->getMessage()),
            );
        } catch (RuntimeException $e) {
            return new ResourceUpdateResult(
                status: ResourceUpdateStatus::Failed,
                errorCode: 'INSTANCE_FETCH_FAILED',
                safeMessage: $this->sanitizeSafeMessage($e->getMessage()),
            );
        }

        try {
            $payload = $this->patchBuilderService()->buildPutPayload($fetched['data'], $target);
        } catch (DomainException $e) {
            $code = str_contains($e->getMessage(), 'NETWORK_LIMIT_NO_NIC')
                ? 'NETWORK_LIMIT_NO_NIC'
                : 'INVALID_RESOURCE_SPEC';

            return new ResourceUpdateResult(
                status: ResourceUpdateStatus::Rejected,
                errorCode: $code,
                safeMessage: $this->sanitizeSafeMessage($e->getMessage()),
            );
        } catch (InvalidArgumentException $e) {
            return new ResourceUpdateResult(
                status: ResourceUpdateStatus::Rejected,
                errorCode: 'INVALID_RESOURCE_SPEC',
                safeMessage: $this->sanitizeSafeMessage($e->getMessage()),
            );
        }

        try {
            $request = $this->client()->withQueryParameters(['project' => $vm->incus_project]);
            if ($fetched['etag'] !== null && $fetched['etag'] !== '') {
                $request = $request->withHeaders(['If-Match' => $fetched['etag']]);
            }

            /** @var Response $putResponse */
            $putResponse = $request->put(sprintf('/1.0/instances/%s', rawurlencode($vm->name)), $payload);
        } catch (FingerprintMismatchException $e) {
            throw $e;
        } catch (ConnectionException) {
            return new ResourceUpdateResult(
                status: ResourceUpdateStatus::Unknown,
                errorCode: 'CONNECTION_ERROR',
                safeMessage: 'Hypervisor connection interrupted while applying resource update.',
            );
        }

        if ($putResponse->status() === 412) {
            return new ResourceUpdateResult(
                status: ResourceUpdateStatus::Rejected,
                errorCode: 'CONCURRENT_MODIFICATION',
                safeMessage: 'Instance configuration was modified concurrently on the hypervisor.',
            );
        }

        if (! $putResponse->successful()) {
            $rawError = Cast::stringOrNull($putResponse->json('error') ?? $putResponse->json('metadata.err'))
                ?? $putResponse->body();

            if ($this->isProjectLimitError($rawError)) {
                return new ResourceUpdateResult(
                    status: ResourceUpdateStatus::Rejected,
                    errorCode: 'PROJECT_LIMIT_EXCEEDED',
                    safeMessage: $this->sanitizeSafeMessage($rawError),
                );
            }
        }

        $waitSeconds = max(0, Cast::int(config('vps.resource_update_wait_seconds', 30), 30));
        $outcome = $this->executeOperation($putResponse, $vm->incus_project, [], $waitSeconds);

        if ($outcome->isSucceeded()) {
            return new ResourceUpdateResult(
                status: ResourceUpdateStatus::Applied,
                operationId: $outcome->operationId,
            );
        }

        if ($outcome->isRunning()) {
            return new ResourceUpdateResult(
                status: ResourceUpdateStatus::Accepted,
                operationId: $outcome->operationId,
            );
        }

        $errMsg = $outcome->errorMessage ?? '';
        if ($this->isProjectLimitError($errMsg)) {
            return new ResourceUpdateResult(
                status: ResourceUpdateStatus::Rejected,
                operationId: $outcome->operationId,
                errorCode: 'PROJECT_LIMIT_EXCEEDED',
                safeMessage: $this->sanitizeSafeMessage($errMsg),
            );
        }

        return new ResourceUpdateResult(
            status: ResourceUpdateStatus::Failed,
            operationId: $outcome->operationId,
            errorCode: 'HYPERVISOR_OPERATION_FAILED',
            safeMessage: $this->sanitizeSafeMessage($errMsg !== '' ? $errMsg : 'Hypervisor operation failed.'),
        );
    }

    #[Override]
    public function getOperationStatus(VirtualMachine $vm, string $operationId): OperationStatus
    {
        $operationId = trim($operationId);
        if ($operationId === '') {
            return new OperationStatus(
                state: OperationState::Unknown,
                errorCode: 'INVALID_OPERATION_ID',
                safeMessage: 'Operation ID cannot be empty.',
            );
        }

        try {
            /** @var Response $response */
            $response = $this->client()
                ->withQueryParameters(['project' => $vm->incus_project])
                ->get(sprintf('/1.0/operations/%s', rawurlencode($operationId)));
        } catch (FingerprintMismatchException $e) {
            throw $e;
        } catch (ConnectionException) {
            return new OperationStatus(
                state: OperationState::Unknown,
                errorCode: 'CONNECTION_ERROR',
                safeMessage: 'Unable to reach hypervisor to check operation status.',
            );
        } catch (Throwable $e) {
            return new OperationStatus(
                state: OperationState::Unknown,
                errorCode: 'OPERATION_QUERY_ERROR',
                safeMessage: $this->sanitizeSafeMessage($e->getMessage()),
            );
        }

        if ($response->status() === 404) {
            return new OperationStatus(
                state: OperationState::Unknown,
                errorCode: 'OPERATION_NOT_FOUND',
                safeMessage: 'Hypervisor operation was not found.',
            );
        }

        if (! $response->successful()) {
            $rawError = Cast::stringOrNull($response->json('error') ?? $response->json('metadata.err'))
                ?? sprintf('HTTP %d', $response->status());

            return new OperationStatus(
                state: OperationState::Unknown,
                errorCode: 'HYPERVISOR_ERROR',
                safeMessage: $this->sanitizeSafeMessage($rawError),
            );
        }

        /** @var mixed $rawBody */
        $rawBody = $response->json();
        $data = is_array($rawBody) ? $rawBody : [];
        $metadata = Cast::arrayOrNull($data['metadata'] ?? null) ?? [];

        $statusStr = strtolower(trim(Cast::string($metadata['status'] ?? ($data['status'] ?? ''))));
        $statusCode = Cast::int($metadata['status_code'] ?? ($data['status_code'] ?? 0));

        $state = match (true) {
            in_array($statusStr, ['running', 'pending', 'cancelling', 'operation created', 'started'], true) => OperationState::Running,
            in_array($statusStr, ['success', 'succeeded'], true) => OperationState::Succeeded,
            in_array($statusStr, ['failure', 'failed', 'cancelled', 'canceled'], true) => OperationState::Failed,
            in_array($statusCode, [100, 101, 103, 104, 105], true) => OperationState::Running,
            $statusCode === 200 => OperationState::Succeeded,
            in_array($statusCode, [400, 401], true) => OperationState::Failed,
            default => OperationState::Unknown,
        };

        if ($state === OperationState::Failed) {
            $err = Cast::stringOrNull($metadata['err'] ?? ($data['error'] ?? null)) ?? 'Hypervisor operation failed.';

            return new OperationStatus(
                state: OperationState::Failed,
                errorCode: 'HYPERVISOR_OPERATION_FAILED',
                safeMessage: $this->sanitizeSafeMessage($err),
            );
        }

        return new OperationStatus(state: $state);
    }

    /**
     * Fetch full instance configuration metadata and ETag header from the hypervisor.
     *
     * @return array{data: array<string, mixed>, etag: ?string}
     *
     * @throws DriverUnavailableException When the hypervisor host cannot be reached or returns HTTP 5xx.
     * @throws FingerprintMismatchException When the hypervisor server TLS public key pin fails verification.
     * @throws RuntimeException When the hypervisor returns a non-success client response or host/cert configuration is invalid.
     */
    protected function fetchInstanceWithEtag(VirtualMachine $vm): array
    {
        try {
            /** @var Response $response */
            $response = $this->client()
                ->withQueryParameters(['project' => $vm->incus_project])
                ->get(sprintf('/1.0/instances/%s', rawurlencode($vm->name)));
        } catch (FingerprintMismatchException $e) {
            throw $e;
        } catch (ConnectionException $e) {
            throw new DriverUnavailableException(
                sprintf('Hypervisor host is unreachable for instance %s: %s', $vm->name, $e->getMessage()),
                0,
                $e,
            );
        }

        if ($response->serverError()) {
            throw new DriverUnavailableException(
                sprintf('Hypervisor returned HTTP %d while retrieving instance %s.', $response->status(), $vm->name)
            );
        }

        if (! $response->successful()) {
            $err = Cast::stringOrNull($response->json('error')) ?? $response->body();
            throw new RuntimeException(
                sprintf('Failed to retrieve instance %s (HTTP %d): %s', $vm->name, $response->status(), $err)
            );
        }

        $metadata = Cast::arrayOrNull($response->json('metadata')) ?? [];
        $etagHeader = $response->header('ETag');
        $etag = $etagHeader !== '' ? $etagHeader : null;

        return [
            'data' => $metadata,
            'etag' => $etag,
        ];
    }

    /**
     * Query the storage pool driver for the instance's root disk device.
     *
     * @param  array<string, mixed>  $instanceData
     */
    protected function resolveStorageDriver(array $instanceData): ?string
    {
        $rootDisk = $this->patchBuilderService()->findRootDiskDevice($instanceData);
        if ($rootDisk === null) {
            return null;
        }

        $poolName = Cast::string($rootDisk['device']['pool'] ?? '');
        if ($poolName === '') {
            return null;
        }

        try {
            /** @var Response $response */
            $response = $this->client()->get(sprintf('/1.0/storage-pools/%s', rawurlencode($poolName)));
        } catch (FingerprintMismatchException $e) {
            throw $e;
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $metadata = Cast::arrayOrNull($response->json('metadata')) ?? [];

        return Cast::stringOrNull($metadata['driver'] ?? null);
    }

    /**
     * Extract the local NIC `nictype` (`null` if no local NIC device exists).
     *
     * @param  array<string, mixed>  $instanceData
     */
    protected function extractLocalNicType(array $instanceData): ?string
    {
        $localNic = $this->patchBuilderService()->findLocalNicDevice($instanceData);
        if ($localNic === null) {
            return null;
        }

        return Cast::string($localNic['device']['nictype'] ?? '');
    }

    protected function patchBuilderService(): InstanceConfigPatchBuilder
    {
        return $this->patchBuilder ?? new InstanceConfigPatchBuilder;
    }

    protected function capabilityMatrixService(): ResourceCapabilityMatrix
    {
        return $this->capabilityMatrix ?? new ResourceCapabilityMatrix;
    }

    protected function isProjectLimitError(string $message): bool
    {
        $lower = strtolower($message);
        if ($lower === '') {
            return false;
        }

        if (str_contains($lower, 'project') && (str_contains($lower, 'limit') || str_contains($lower, 'quota') || str_contains($lower, 'exceed'))) {
            return true;
        }

        return str_contains($lower, 'reached maximum') || str_contains($lower, 'quota exceeded');
    }

    protected function sanitizeSafeMessage(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        $cleaned = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message));
        if ($cleaned === '') {
            return null;
        }

        return substr($cleaned, 0, 300);
    }
}
