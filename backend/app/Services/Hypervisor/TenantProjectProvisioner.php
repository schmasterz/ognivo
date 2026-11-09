<?php

declare(strict_types=1);

namespace App\Services\Hypervisor;

use App\Models\Host;
use App\Models\User;
use App\Models\UserQuota;
use App\Services\PanelCertificateService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/**
 * Service responsible for idempotent creation, hardening, profile repair,
 * and quota limit synchronization of tenant Incus/LXD projects.
 */
class TenantProjectProvisioner
{
    public function __construct(
        protected readonly ?HypervisorGateway $gateway = null,
        protected readonly ?PanelCertificateService $certService = null,
    ) {}

    /**
     * Build an authenticated HTTP client for a target host.
     */
    public function clientForHost(Host $host): PendingRequest
    {
        $service = $this->certService ?? app(PanelCertificateService::class);
        $clientCert = $service->getActiveCertificate() ?? $service->getRetiringCertificate() ?? $service->generate(365);
        $gateway = $this->gateway ?? app(HypervisorGateway::class);

        return $gateway->client($host, $clientCert);
    }

    /**
     * Map a UserQuota model to Incus project configuration limits.
     * Unlimited values (-1) omit the corresponding key.
     *
     * @return array<string, string>
     */
    public function mapQuotaToProjectLimits(?UserQuota $quota): array
    {
        if ($quota === null) {
            return [];
        }

        $limits = [];

        if ($quota->max_vms !== -1) {
            $limits['limits.instances'] = (string) $quota->max_vms;
        }

        if ($quota->max_cpu_cores !== -1) {
            $limits['limits.cpu'] = (string) $quota->max_cpu_cores;
        }

        if ($quota->max_ram_mb !== -1) {
            $limits['limits.memory'] = sprintf('%dMB', $quota->max_ram_mb);
        }

        if ($quota->max_storage_gb !== -1) {
            $limits['limits.disk'] = sprintf('%dGB', $quota->max_storage_gb);
        }

        return $limits;
    }

    /**
     * Build full project configuration including strict tenant isolation and quota limits.
     *
     * @return array<string, string>
     */
    public function buildProjectConfig(?UserQuota $quota = null): array
    {
        return array_merge([
            'restricted' => 'true',
            'restricted.containers.privilege' => 'unprivileged',
            'restricted.devices.nic' => 'managed',
            'restricted.devices.disk' => 'managed',
            'features.images' => 'true',
            'features.profiles' => 'true',
            'features.storage.volumes' => 'true',
        ], $this->mapQuotaToProjectLimits($quota));
    }

    /**
     * Ensure the tenant isolation project exists, is hardened, and default profile is verified and repaired.
     */
    public function ensureProject(PendingRequest $client, string $project, ?UserQuota $quota = null): void
    {
        if ($project === 'default' || $project === '') {
            return;
        }

        $projectConfig = $this->buildProjectConfig($quota);

        /** @var Response $res */
        $res = $client->get(sprintf('/1.0/projects/%s', $project));

        if (! $res->successful()) {
            // Project does not exist, create it
            $payload = [
                'name' => $project,
                'description' => sprintf('Tenant isolation project %s', $project),
                'config' => $projectConfig,
            ];

            $createRes = $client->post('/1.0/projects', $payload);

            if (! $createRes->successful()) {
                // Fallback for hypervisors that do not support per-project profiles
                $fallbackConfig = $projectConfig;
                $fallbackConfig['features.profiles'] = 'false';
                $client->post('/1.0/projects', [
                    'name' => $project,
                    'description' => sprintf('Tenant isolation project %s', $project),
                    'config' => $fallbackConfig,
                ]);
            }
        } else {
            // Project exists, update config to ensure hardening and up-to-date quota limits
            $client->put(sprintf('/1.0/projects/%s', $project), [
                'description' => sprintf('Tenant isolation project %s', $project),
                'config' => $projectConfig,
            ]);
        }

        // Always verify and repair default profile if needed
        $this->verifyAndRepairDefaultProfile($client, $project);
    }

    /**
     * Verify that the default profile in the project has both root disk and nic; repair if empty or missing.
     */
    public function verifyAndRepairDefaultProfile(PendingRequest $client, string $project): void
    {
        try {
            /** @var Response $profileRes */
            $profileRes = $client
                ->withQueryParameters(['project' => $project])
                ->get('/1.0/profiles/default');

            $needsRepair = true;

            if ($profileRes->successful()) {
                /** @var array<string, mixed> $body */
                $body = $profileRes->json() ?? [];
                /** @var array<string, mixed> $metadata */
                $metadata = isset($body['metadata']) && is_array($body['metadata']) ? $body['metadata'] : $body;
                /** @var array<string, mixed> $devices */
                $devices = isset($metadata['devices']) && is_array($metadata['devices']) ? $metadata['devices'] : [];

                $hasRootDisk = false;
                $hasNic = false;

                foreach ($devices as $devName => $device) {
                    if (! is_array($device)) {
                        continue;
                    }
                    $type = $device['type'] ?? '';
                    $path = $device['path'] ?? '';

                    if ($type === 'disk' && ($path === '/' || $devName === 'root')) {
                        $hasRootDisk = true;
                    }
                    if ($type === 'nic') {
                        $hasNic = true;
                    }
                }

                if ($hasRootDisk && $hasNic) {
                    $needsRepair = false;
                }
            }

            if ($needsRepair) {
                $storagePool = $this->discoverStoragePool($client);
                $network = $this->discoverNetwork($client);

                $client
                    ->withQueryParameters(['project' => $project])
                    ->put('/1.0/profiles/default', [
                        'devices' => [
                            'root' => [
                                'path' => '/',
                                'pool' => $storagePool,
                                'type' => 'disk',
                            ],
                            'eth0' => [
                                'name' => 'eth0',
                                'network' => $network,
                                'type' => 'nic',
                            ],
                        ],
                    ]);
            }
        } catch (\Throwable $e) {
            Log::warning(sprintf('Profile verification/repair warning for project %s: %s', $project, $e->getMessage()));
        }
    }

    /**
     * Synchronize quota limits to an existing project on a specific host.
     */
    public function syncProjectLimits(PendingRequest $client, string $project, UserQuota $quota): bool
    {
        if ($project === 'default' || $project === '') {
            return false;
        }

        $config = $this->buildProjectConfig($quota);

        /** @var Response $response */
        $response = $client->put(sprintf('/1.0/projects/%s', $project), [
            'description' => sprintf('Tenant isolation project %s', $project),
            'config' => $config,
        ]);

        return $response->successful();
    }

    /**
     * Re-sync project limits across all active hosts where the user has workloads.
     */
    public function syncUserQuotaAcrossHosts(UserQuota $quota): void
    {
        /** @var User|null $user */
        $user = $quota->user ?? User::find($quota->user_id);
        if ($user === null) {
            return;
        }

        $projectName = $user->project_name ?? sprintf('proj-%s', $user->id);
        if ($projectName === '') {
            return;
        }

        // Find active compute nodes where the user has virtual machines OR all active non-fake hosts
        $hosts = Host::where('is_active', true)
            ->where('driver', '!=', 'fake')
            ->where(function ($q) use ($user): void {
                $q->whereHas('virtualMachines', fn ($vmQ) => $vmQ->where('user_id', $user->id));
            })
            ->get();

        if ($hosts->isEmpty()) {
            $hosts = Host::where('is_active', true)->where('driver', '!=', 'fake')->get();
        }

        foreach ($hosts as $host) {
            try {
                $client = $this->clientForHost($host);
                $this->syncProjectLimits($client, $projectName, $quota);
            } catch (\Throwable $e) {
                Log::error(sprintf('Failed syncing quota limits to host #%d for project %s: %s', $host->id, $projectName, $e->getMessage()));
            }
        }
    }

    /**
     * Discover an available storage pool on the target hypervisor host.
     */
    public function discoverStoragePool(PendingRequest $client): string
    {
        try {
            /** @var Response $poolsRes */
            $poolsRes = $client->get('/1.0/storage-pools');
            if ($poolsRes->successful()) {
                $poolsData = $poolsRes->json();
                /** @var list<mixed> $poolList */
                $poolList = is_array($poolsData) && isset($poolsData['metadata']) && is_array($poolsData['metadata'])
                    ? $poolsData['metadata']
                    : [];
                if (! empty($poolList) && is_string($poolList[0])) {
                    return basename($poolList[0]);
                }
            }
        } catch (\Throwable) {
            // Default fallback
        }

        return 'default';
    }

    /**
     * Discover an available network on the target hypervisor host.
     */
    public function discoverNetwork(PendingRequest $client): string
    {
        try {
            /** @var Response $netsRes */
            $netsRes = $client->get('/1.0/networks');
            if ($netsRes->successful()) {
                $netsData = $netsRes->json();
                /** @var list<mixed> $netList */
                $netList = is_array($netsData) && isset($netsData['metadata']) && is_array($netsData['metadata'])
                    ? $netsData['metadata']
                    : [];
                foreach ($netList as $netUrl) {
                    if (is_string($netUrl)) {
                        $name = basename($netUrl);
                        if ($name !== 'lo' && $name !== '') {
                            return $name;
                        }
                    }
                }
            }
        } catch (\Throwable) {
            // Default fallback
        }

        return 'incusbr0';
    }
}
