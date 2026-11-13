<?php

declare(strict_types=1);

namespace App\Services\Hypervisor\Drivers;

use App\Exceptions\FingerprintMismatchException as BaseFingerprintMismatchException;
use App\Models\Host;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\Hypervisor\Contracts\HypervisorDriverInterface;
use App\Services\Hypervisor\Exceptions\FingerprintMismatchException;
use App\Services\Hypervisor\HypervisorGateway;
use App\Services\Hypervisor\InstanceSpecBuilder;
use App\Services\Hypervisor\TenantProjectProvisioner;
use App\Services\PanelCertificateService;
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

        $service = $this->certService ?? app(PanelCertificateService::class);
        $clientCert = $service->getActiveCertificate() ?? $service->getRetiringCertificate();
        $gateway = $this->gateway ?? app(HypervisorGateway::class);

        return $gateway->client($this->host, $clientCert);
    }

    /**
     * Handle hypervisor operation response, waiting for asynchronous operations to finish.
     */
    protected function handleOperationResponse(Response $response, ?string $project = null): bool
    {
        $this->lastError = null;

        if (! $response->successful()) {
            $err = $response->json('error') ?? $response->json('metadata.err');
            $errStr = is_string($err) && $err !== ''
                ? $err
                : sprintf('HTTP %d: %s', $response->status(), substr(trim($response->body()), 0, 300));
            $this->lastError = $errStr;
            Log::error(sprintf('Hypervisor synchronous request failed (HTTP %d, project %s): %s', $response->status(), $project ?? 'default', $errStr));

            return false;
        }

        /** @var array<string, mixed> $data */
        $data = $response->json() ?? [];
        $type = $data['type'] ?? 'sync';

        // Incus and LXD execute instance operations asynchronously (HTTP 202)
        if ($type === 'async' && isset($data['operation']) && is_string($data['operation'])) {
            $operationUrl = $data['operation'];
            $waitUrl = sprintf('%s/wait', $operationUrl);
            /** @var array<string, mixed> $waitQuery */
            $waitQuery = ['timeout' => 120];
            if ($project !== null && $project !== '') {
                $waitQuery['project'] = $project;
            }

            try {
                /** @var Response $waitResponse */
                $waitResponse = $this->client()->get($waitUrl, $waitQuery);
                if (! $waitResponse->successful()) {
                    $waitErr = $waitResponse->json('error') ?? $waitResponse->json('metadata.err');
                    $this->lastError = is_string($waitErr) && $waitErr !== ''
                        ? $waitErr
                        : sprintf('HTTP %d while waiting for operation', $waitResponse->status());
                    Log::error(sprintf('Hypervisor operation wait failed: %s', $this->lastError));

                    return false;
                }

                /** @var array<string, mixed> $waitData */
                $waitData = $waitResponse->json() ?? [];
                /** @var array<string, mixed> $metadata */
                $metadata = isset($waitData['metadata']) && is_array($waitData['metadata']) ? $waitData['metadata'] : [];
                $status = isset($metadata['status']) && is_string($metadata['status'])
                    ? $metadata['status']
                    : ($waitData['status'] ?? '');

                if ($status !== 'Success') {
                    $err = $metadata['err'] ?? ($waitData['error'] ?? 'Operation failed');
                    $encoded = is_scalar($err) || is_array($err) ? json_encode($err) : false;
                    $errStr = is_string($err) && $err !== '' ? $err : ($encoded !== false ? $encoded : 'Operation failed');
                    $this->lastError = $errStr;
                    Log::error(sprintf('Hypervisor operation failed: %s', $errStr));

                    return false;
                }

                return true;
            } catch (BaseFingerprintMismatchException $e) {
                throw $e instanceof FingerprintMismatchException
                    ? $e
                    : new FingerprintMismatchException($e->getActualFingerprint(), $e->getExpectedFingerprint(), $e->getMessage());
            } catch (Throwable $e) {
                $this->lastError = $e->getMessage();
                Log::error(sprintf('Exception while waiting for hypervisor operation: %s', $e->getMessage()));

                return false;
            }
        }

        return $response->successful();
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
        } catch (BaseFingerprintMismatchException $e) {
            throw $e instanceof FingerprintMismatchException
                ? $e
                : new FingerprintMismatchException($e->getActualFingerprint(), $e->getExpectedFingerprint(), $e->getMessage());
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
        $network = $availableNetworks[0] ?? 'incusbr0';

        $builder = $this->instanceSpecBuilder ?? app(InstanceSpecBuilder::class);
        $payload = $builder->build($vm, null, $storagePool, $network, null, null, $availableNetworks);

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

        return $this->handleOperationResponse($response, $vm->incus_project);
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

        return $this->handleOperationResponse($response, $vm->incus_project);
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

        return $this->handleOperationResponse($response, $vm->incus_project);
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
                return "Unable to fetch console log from hypervisor. Instance status: {$this->getInstanceStatus($vm)}.";
            }

            $body = $response->body();
            if (strlen($body) > 51200) {
                $body = substr($body, -51200);
            }

            return $body !== '' ? $body : 'Console buffer is empty.';
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
    public function createSnapshot(VirtualMachine $vm, string $name, bool $stateful = false): bool
    {
        /** @var Response $response */
        $response = $this->client()
            ->withQueryParameters(['project' => $vm->incus_project])
            ->post(sprintf('/1.0/instances/%s/snapshots', rawurlencode($vm->name)), [
                'name' => $name,
                'stateful' => $stateful,
            ]);

        return $this->handleOperationResponse($response, $vm->incus_project);
    }

    /**
     * List all snapshots for the specified instance.
     *
     * @return list<array{name: string, created_at: string, stateful: bool, size: int|null}>
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
                    'stateful' => isset($item['stateful']) && (bool) $item['stateful'],
                    'size' => $size,
                ];
            } elseif (is_string($item)) {
                $snapshots[] = [
                    'name' => basename($item),
                    'created_at' => '',
                    'stateful' => false,
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

        return $this->handleOperationResponse($response, $vm->incus_project);
    }

    #[Override]
    public function createConsoleSession(VirtualMachine $vm, string $type = 'console'): array
    {
        if ($vm->instance_type === 'container' && $type === 'vga') {
            throw new InvalidArgumentException('Containers do not support graphical VGA console.');
        }

        /** @var Response $response */
        $response = $this->client()
            ->withQueryParameters([
                'project' => $vm->incus_project,
                'wait-for-websocket' => 'true',
            ])
            ->post(sprintf('/1.0/instances/%s/console', $vm->name), [
                'type' => $type,
                'width' => 80,
                'height' => 24,
            ]);

        if (! $response->successful()) {
            $err = $response->json('error');
            $msg = is_string($err) && $err !== '' ? $err : $response->body();
            throw new RuntimeException(sprintf('Failed to create console session on hypervisor: %s', $msg !== '' ? $msg : 'Unknown error'));
        }

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
        $rawFds = $nestedMeta['fds'] ?? null;
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
        ];
    }
}
