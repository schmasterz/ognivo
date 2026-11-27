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
            $limits['limits.memory'] = sprintf('%dMiB', $quota->max_ram_mb);
        }

        if ($quota->max_storage_gb !== -1) {
            $limits['limits.disk'] = sprintf('%dGiB', $quota->max_storage_gb);
        }

        return $limits;
    }

    /**
     * Build full project configuration including strict tenant isolation and quota limits.
     * Note: restricted.devices.nic is set to 'allow' so the platform can attach routed,
     * macvlan, and bridged NICs with host parent interfaces to tenant workloads.
     *
     * @return array<string, string>
     */
    public function buildProjectConfig(?UserQuota $quota = null): array
    {
        return array_merge([
            'restricted' => 'true',
            'restricted.snapshots' => 'allow',
            'restricted.containers.privilege' => 'unprivileged',
            'restricted.devices.nic' => 'allow',
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
        $res = (clone $client)->get(sprintf('/1.0/projects/%s', $project));

        if (! $res->successful()) {
            // Project does not exist, create it
            $payload = [
                'name' => $project,
                'description' => sprintf('Tenant isolation project %s', $project),
                'config' => $projectConfig,
            ];

            $createRes = (clone $client)->post('/1.0/projects', $payload);

            if (! $createRes->successful()) {
                // Fallback for hypervisors that do not support per-project profiles
                $fallbackConfig = $projectConfig;
                $fallbackConfig['features.profiles'] = 'false';
                (clone $client)->post('/1.0/projects', [
                    'name' => $project,
                    'description' => sprintf('Tenant isolation project %s', $project),
                    'config' => $fallbackConfig,
                ]);
            }
        } else {
            // Project exists, update config to ensure hardening and up-to-date quota limits
            (clone $client)->put(sprintf('/1.0/projects/%s', $project), [
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
            $profileRes = (clone $client)
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

                (clone $client)
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
        $response = (clone $client)->put(sprintf('/1.0/projects/%s', $project), [
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
            $poolsRes = (clone $client)->get('/1.0/storage-pools');
            if ($poolsRes->successful()) {
                $poolsData = $poolsRes->json();
                /** @var list<mixed> $poolList */
                $poolList = is_array($poolsData) && isset($poolsData['metadata']) && is_array($poolsData['metadata'])
                    ? $poolsData['metadata']
                    : [];
                if (! empty($poolList)) {
                    if (is_string($poolList[0])) {
                        return basename($poolList[0]);
                    }
                    if (is_array($poolList[0]) && isset($poolList[0]['name']) && is_string($poolList[0]['name'])) {
                        return $poolList[0]['name'];
                    }
                }
            }
        } catch (\Throwable) {
            // Default fallback
        }

        return 'default';
    }

    /**
     * List all non-loopback network interface names on the target hypervisor host.
     *
     * @return list<string>
     */
    public function listNetworkNames(PendingRequest $client): array
    {
        $names = [];

        try {
            /** @var Response $netsRes */
            $netsRes = (clone $client)->get('/1.0/networks');
            if ($netsRes->successful()) {
                $netsData = $netsRes->json();
                /** @var list<mixed> $netList */
                $netList = is_array($netsData) && isset($netsData['metadata']) && is_array($netsData['metadata'])
                    ? $netsData['metadata']
                    : [];
                foreach ($netList as $netItem) {
                    $name = '';
                    if (is_string($netItem)) {
                        $name = basename($netItem);
                    } elseif (is_array($netItem) && isset($netItem['name']) && is_string($netItem['name'])) {
                        $name = $netItem['name'];
                    }
                    if ($name !== '' && $name !== 'lo') {
                        $names[] = $name;
                    }
                }
            }
        } catch (\Throwable) {
            // Ignore discovery error
        }

        return array_values(array_unique($names));
    }

    /**
     * Discover an available network on the target hypervisor host for default profile repair.
     * Prefers managed bridges (incusbr0/lxdbr0) over physical/docker interfaces.
     */
    public function discoverNetwork(PendingRequest $client): string
    {
        $names = $this->listNetworkNames($client);
        if (empty($names)) {
            return 'incusbr0';
        }

        foreach ($names as $name) {
            if (str_starts_with($name, 'incusbr') || str_starts_with($name, 'lxdbr')) {
                return $name;
            }
        }

        foreach ($names as $name) {
            if (! str_starts_with($name, 'docker') && ! str_starts_with($name, 'tailscale') && ! str_starts_with($name, 'veth')) {
                return $name;
            }
        }

        return $names[0];
    }

    /**
     * Discover detailed network interfaces and storage pools on a compute node.
     *
     * @return array{
     *   reachable: bool,
     *   networks: list<array{name: string, type: string, managed: bool, addresses: list<string>, recommended_modes: list<string>}>,
     *   storage_pools: list<array{name: string, driver: string, status: string}>
     * }
     */
    public function discoverHostResources(Host $host): array
    {
        if ($host->driver === 'fake') {
            return [
                'reachable' => true,
                'networks' => [
                    [
                        'name' => 'eth0',
                        'type' => 'physical',
                        'managed' => false,
                        'addresses' => [],
                        'recommended_modes' => ['routed', 'macvlan'],
                    ],
                    [
                        'name' => 'incusbr0',
                        'type' => 'bridge',
                        'managed' => true,
                        'addresses' => ['10.0.0.1/24'],
                        'recommended_modes' => ['bridged', 'routed'],
                    ],
                ],
                'storage_pools' => [
                    [
                        'name' => 'default',
                        'driver' => 'dir',
                        'status' => 'Created',
                    ],
                ],
            ];
        }

        $reachable = false;
        $networks = [];
        $storagePools = [];

        try {
            $client = $this->clientForHost($host);

            /** @var Response $netsRes */
            $netsRes = (clone $client)->withQueryParameters(['recursion' => 1])->get('/1.0/networks');
            if ($netsRes->successful()) {
                $reachable = true;
                $meta = $netsRes->json('metadata');
                if (is_array($meta)) {
                    foreach ($meta as $item) {
                        if (is_string($item)) {
                            $name = basename($item);
                            if ($name === 'lo' || str_starts_with($name, 'veth')) {
                                continue;
                            }
                            $isBridge = str_starts_with($name, 'incusbr') || str_starts_with($name, 'lxdbr') || str_starts_with($name, 'br');
                            $networks[] = [
                                'name' => $name,
                                'type' => $isBridge ? 'bridge' : 'physical',
                                'managed' => str_starts_with($name, 'incusbr') || str_starts_with($name, 'lxdbr'),
                                'addresses' => [],
                                'recommended_modes' => $isBridge ? ['bridged', 'routed'] : ['routed', 'macvlan'],
                            ];
                        } elseif (is_array($item)) {
                            $name = isset($item['name']) && is_string($item['name']) ? $item['name'] : '';
                            $type = isset($item['type']) && is_string($item['type']) ? $item['type'] : 'unknown';
                            $managed = isset($item['managed']) && (bool) $item['managed'];

                            if ($name === '' || $name === 'lo' || $type === 'loopback' || str_starts_with($name, 'veth')) {
                                continue;
                            }

                            $addresses = [];
                            $config = isset($item['config']) && is_array($item['config']) ? $item['config'] : [];
                            if (isset($config['ipv4.address']) && is_string($config['ipv4.address']) && $config['ipv4.address'] !== 'none') {
                                $addresses[] = $config['ipv4.address'];
                            }

                            $recommendedModes = match ($type) {
                                'bridge' => ['bridged', 'routed'],
                                'physical', 'bond', 'vlan' => ['routed', 'macvlan'],
                                default => ['routed'],
                            };

                            $networks[] = [
                                'name' => $name,
                                'type' => $type,
                                'managed' => $managed,
                                'addresses' => $addresses,
                                'recommended_modes' => $recommendedModes,
                            ];
                        }
                    }
                }
            }

            /** @var Response $poolsRes */
            $poolsRes = (clone $client)->withQueryParameters(['recursion' => 1])->get('/1.0/storage-pools');
            if ($poolsRes->successful()) {
                $reachable = true;
                $poolMeta = $poolsRes->json('metadata');
                if (is_array($poolMeta)) {
                    foreach ($poolMeta as $item) {
                        if (is_string($item)) {
                            $storagePools[] = [
                                'name' => basename($item),
                                'driver' => 'unknown',
                                'status' => 'Created',
                            ];
                        } elseif (is_array($item) && isset($item['name']) && is_string($item['name'])) {
                            $storagePools[] = [
                                'name' => $item['name'],
                                'driver' => isset($item['driver']) && is_string($item['driver']) ? $item['driver'] : 'unknown',
                                'status' => isset($item['status']) && is_string($item['status']) ? $item['status'] : 'Created',
                            ];
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning(sprintf('Host resource discovery warning for host #%d (%s): %s', $host->id, $host->name, $e->getMessage()));
        }

        // Sort physical, bond, vlan, and managed bridges ahead of auxiliary interfaces (docker0, tailscale0)
        usort($networks, static function (array $a, array $b): int {
            /**
             * @param  array<string, mixed>  $net
             */
            $score = static function (array $net): int {
                $netName = isset($net['name']) && is_string($net['name']) ? $net['name'] : '';
                $netType = isset($net['type']) && is_string($net['type']) ? $net['type'] : '';

                if (str_starts_with($netName, 'docker') || str_starts_with($netName, 'tailscale')) {
                    return 30;
                }
                if (in_array($netType, ['physical', 'bond', 'vlan'], true)) {
                    return 10;
                }
                if ($netType === 'bridge') {
                    return 20;
                }

                return 25;
            };

            return $score($a) <=> $score($b);
        });

        return [
            'reachable' => $reachable,
            'networks' => $networks,
            'storage_pools' => $storagePools,
        ];
    }
}
