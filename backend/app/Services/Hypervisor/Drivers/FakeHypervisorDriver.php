<?php

declare(strict_types=1);

namespace App\Services\Hypervisor\Drivers;

use App\DTO\Host\HostResourcesDTO;
use App\DTO\Hypervisor\InstanceResourceSpec;
use App\DTO\Hypervisor\OperationState;
use App\DTO\Hypervisor\OperationStatus;
use App\DTO\Hypervisor\ResourceKind;
use App\DTO\Hypervisor\ResourceUpdateAssessment;
use App\DTO\Hypervisor\ResourceUpdateResult;
use App\DTO\Hypervisor\ResourceUpdateStatus;
use App\DTO\Hypervisor\ResourceUpdateVerdict;
use App\DTO\Incus\RawInstanceMetricsDTO;
use App\Exceptions\DriverUnavailableException;
use App\Models\Host;
use App\Models\VirtualMachine;
use App\Services\Hypervisor\Contracts\HypervisorDriverInterface;
use App\Services\Hypervisor\OpenMetricsParser;
use App\Services\Hypervisor\ResourceCapabilityMatrix;
use Illuminate\Support\Facades\Cache;
use Override;

/**
 * Mock hypervisor driver for local development and automated testing environments.
 */
final class FakeHypervisorDriver implements HypervisorDriverInterface
{
    /**
     * @var list<string>
     */
    public array $deletedInstances = [];

    /**
     * @var list<string>
     */
    public array $cancelledOperations = [];

    /**
     * @var array<string, callable>
     */
    public array $actionCallbacks = [];

    public ?HostResourcesDTO $hostResources = null;

    /**
     * @var array<int, array<string, RawInstanceMetricsDTO>|string>
     */
    public array $hostMetricsOverrides = [];

    public int $metricsRequestCount = 0;

    /**
     * @var array<int, int>
     */
    private array $simulatedScrapeCounts = [];

    /**
     * @param  array<string, RawInstanceMetricsDTO>|string  $metrics
     */
    public function setHostMetrics(Host|int $host, array|string $metrics): self
    {
        $hostId = $host instanceof Host ? $host->id : $host;
        $this->hostMetricsOverrides[$hostId] = $metrics;

        return $this;
    }

    public function setActionCallback(string $action, callable $callback): self
    {
        $this->actionCallbacks[$action] = $callback;

        return $this;
    }

    #[Override]
    public function createInstance(VirtualMachine $vm): bool
    {
        if (isset($this->actionCallbacks['createInstance'])) {
            return (bool) ($this->actionCallbacks['createInstance'])($vm);
        }

        if ($this->shouldFail('createInstance')) {
            return false;
        }

        return true;
    }

    #[Override]
    public function startInstance(VirtualMachine $vm): bool
    {
        if (isset($this->actionCallbacks['startInstance'])) {
            return (bool) ($this->actionCallbacks['startInstance'])($vm);
        }

        if ($this->shouldFail('startInstance')) {
            return false;
        }

        return true;
    }

    #[Override]
    public function stopInstance(VirtualMachine $vm, bool $force = false): bool
    {
        if ($this->shouldFail('stopInstance')) {
            return false;
        }

        return true;
    }

    /**
     * @var array<string, bool>
     */
    public array $shouldFail = [];

    public function shouldFail(string $action): bool
    {
        if (isset($this->shouldFail[$action])) {
            return (bool) $this->shouldFail[$action];
        }

        try {
            return (bool) Cache::get("fake_driver:fail:{$action}", false);
        } catch (\Throwable) {
            return false;
        }
    }

    #[Override]
    public function suspendInstance(VirtualMachine $vm): bool
    {
        if ($this->shouldFail('suspendInstance')) {
            return false;
        }

        return true;
    }

    private function instanceKey(VirtualMachine $vm): string
    {
        $project = $vm->incus_project !== '' ? $vm->incus_project : 'default';

        return sprintf('%s/%s', $project, $vm->name);
    }

    #[Override]
    public function deleteInstance(VirtualMachine $vm): bool
    {
        if ($this->shouldFail('deleteInstance')) {
            return false;
        }

        $key = $this->instanceKey($vm);
        $this->deletedInstances[] = $vm->name;

        try {
            Cache::put("fake_driver:deleted:{$vm->name}", true, 3600);
            Cache::forget("fake_driver:state:{$vm->name}");
            Cache::forget("fake_driver:status:{$vm->name}");
            Cache::forget("fake_driver:snapshots:{$key}");
            Cache::forget("fake_driver:snapshots:{$vm->name}");
            Cache::forget("fake_driver:restored_snapshot:{$key}");
            Cache::forget("fake_driver:restored_snapshot:{$vm->name}");
            Cache::forget("fake_driver:marker:{$key}");
            Cache::forget("fake_driver:marker:{$vm->name}");
        } catch (\Throwable) {
        }

        unset(
            $this->snapshots[$key],
            $this->snapshots[$vm->name],
            $this->snapshotStates[$key],
            $this->snapshotStates[$vm->name],
            $this->restoredSnapshots[$key],
            $this->restoredSnapshots[$vm->name],
            $this->stateMarkers[$key],
            $this->stateMarkers[$vm->name],
        );

        return true;
    }

    public function wasInstanceDeleted(VirtualMachine|string $vm): bool
    {
        $name = $vm instanceof VirtualMachine ? $vm->name : $vm;

        if (in_array($name, $this->deletedInstances, true)) {
            return true;
        }

        try {
            return (bool) Cache::get("fake_driver:deleted:{$name}", false);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @var array<string, list<array{name: string, created_at: string, size: int|null}>>
     */
    public array $snapshots = [];

    /**
     * @var array<string, string>
     */
    public array $restoredSnapshots = [];

    /**
     * @var array<string, string|null>
     */
    public array $stateMarkers = [];

    /**
     * @var array<string, array<string, array{status: string|null, state: array<string, mixed>|null, marker: string|null}>>
     */
    private array $snapshotStates = [];

    /**
     * @var list<string>
     */
    public array $projects = ['default'];

    /**
     * @var list<array{name: string, project: string, status: string}>
     */
    public array $instances = [];

    /**
     * @var array<string, string>
     */
    public array $statuses = [];

    #[Override]
    public function getInstanceStatus(VirtualMachine $vm): string
    {
        if (isset($this->statuses[$vm->name])) {
            return $this->statuses[$vm->name];
        }

        try {
            $cached = Cache::get("fake_driver:status:{$vm->name}");
            if (is_string($cached)) {
                return $cached;
            }
        } catch (\Throwable) {
        }

        return 'Running';
    }

    public function setInstanceStatus(VirtualMachine|string $vm, string $status): self
    {
        $name = $vm instanceof VirtualMachine ? $vm->name : $vm;
        $this->statuses[$name] = $status;

        try {
            Cache::put("fake_driver:status:{$name}", $status, 3600);
        } catch (\Throwable) {
        }

        return $this;
    }

    /**
     * @var array<string, array<string, mixed>>
     */
    public array $instanceStates = [];

    /**
     * @param  array<string, mixed>  $state
     */
    public function setInstanceState(VirtualMachine|string $vm, array $state): self
    {
        $name = $vm instanceof VirtualMachine ? $vm->name : $vm;
        $this->instanceStates[$name] = $state;

        try {
            Cache::put("fake_driver:state:{$name}", $state, 3600);
        } catch (\Throwable) {
        }

        return $this;
    }

    #[Override]
    public function getInstanceState(VirtualMachine $vm): array
    {
        $key = $this->instanceKey($vm);
        $restored = $this->getRestoredSnapshot($vm);

        if (isset($this->instanceStates[$vm->name])) {
            $state = $this->instanceStates[$vm->name];
            if ($restored !== null) {
                $state['restored_snapshot'] = $restored;
            }

            return $state;
        }

        try {
            $cached = Cache::get("fake_driver:state:{$vm->name}");
            if (is_array($cached)) {
                /** @var array<string, mixed> $cached */
                if ($restored !== null) {
                    $cached['restored_snapshot'] = $restored;
                }

                return $cached;
            }
        } catch (\Throwable) {
        }

        $state = [
            'status' => $this->getInstanceStatus($vm),
            'network' => [
                'eth0' => [
                    'addresses' => [
                        [
                            'family' => 'inet',
                            'address' => $vm->ipv4_address ?? '127.0.0.1',
                            'netmask' => '32',
                            'scope' => 'global',
                        ],
                    ],
                ],
            ],
        ];

        if ($restored !== null) {
            $state['restored_snapshot'] = $restored;
        }

        return $state;
    }

    #[Override]
    public function getInstanceConsoleLog(VirtualMachine $vm): string
    {
        $ip = $vm->ipv4_address ?? '169.254.0.2';

        return implode("\n", [
            '[    0.000000] Linux version 6.8.0-45-generic (buildd@lcy02-amd64-089) #45-Ubuntu SMP',
            '[    0.000000] Command line: BOOT_IMAGE=/boot/vmlinuz root=UUID=... ro console=ttyS0',
            '[    0.124892] Initializing cgroup subsys cpuset',
            "[    0.345120] systemd[1]: Inserted module 'autofs4'",
            '[    0.812450] systemd[1]: Detected virtualization kvm.',
            '[    1.204512] systemd[1]: Starting systemd-udevd.service...',
            '[    2.105432] systemd[1]: Started systemd-networkd.service.',
            "[    2.512340] cloud-init[412]: Cloud-init v. 24.1.3 running 'init-local' at ".now()->toIso8601String(),
            '[    3.210450] cloud-init[430]: Generating locales (this might take a while)...',
            '[    3.890120] cloud-init[430]: Applying Netplan v2 configuration for eth0 routed NIC...',
            "[    4.102340] cloud-init[430]: Configured IP: {$ip}/32 via 169.254.0.1 on-link",
            '[    4.891230] systemd[1]: Reached target Cloud-init target.',
            '[    5.120450] systemd[1]: Started OpenSSH server daemon.',
            '[    5.204120] cloud-init[512]: Cloud-init v. 24.1.3 finished at '.now()->toIso8601String().'. Datasource DataSourceNoCloud.',
            "[    5.210000] Ubuntu 24.04 LTS {$vm->name} ttyS0",
        ]);
    }

    /**
     * @return list<string>
     */
    #[Override]
    public function listProjects(): array
    {
        return $this->projects;
    }

    /**
     * @return list<array{name: string, project: string, status: string}>
     */
    #[Override]
    public function listInstances(?string $project = null): array
    {
        if ($project === null) {
            return $this->instances;
        }

        return array_values(array_filter(
            $this->instances,
            fn (array $inst): bool => ($inst['project'] ?? 'default') === $project
        ));
    }

    #[Override]
    public function deleteProject(string $project): bool
    {
        if ($this->shouldFail('deleteProject')) {
            return false;
        }

        $key = array_search($project, $this->projects, true);
        if ($key !== false) {
            unset($this->projects[$key]);
            $this->projects = array_values($this->projects);
        }

        return true;
    }

    public function setFailAction(string $action, bool $fail = true): self
    {
        $this->shouldFail[$action] = $fail;

        try {
            Cache::put("fake_driver:fail:{$action}", $fail, 3600);
        } catch (\Throwable) {
        }

        return $this;
    }

    /**
     * @return list<array{name: string, created_at: string, size: int|null}>
     */
    private function getSnapshotsList(VirtualMachine $vm): array
    {
        $key = $this->instanceKey($vm);

        if (isset($this->snapshots[$key])) {
            return $this->snapshots[$key];
        }

        if (isset($this->snapshots[$vm->name])) {
            return $this->snapshots[$vm->name];
        }

        try {
            $cached = Cache::get("fake_driver:snapshots:{$key}");
            if (is_array($cached)) {
                /** @var list<array{name: string, created_at: string, size: int|null}> $cached */
                return $cached;
            }

            $cachedLegacy = Cache::get("fake_driver:snapshots:{$vm->name}");
            if (is_array($cachedLegacy)) {
                /** @var list<array{name: string, created_at: string, size: int|null}> $cachedLegacy */
                return $cachedLegacy;
            }
        } catch (\Throwable) {
        }

        return [];
    }

    #[Override]
    public function createSnapshot(VirtualMachine $vm, string $name): bool
    {
        if ($this->shouldFail('createSnapshot')) {
            return false;
        }

        if ($name === '') {
            return false;
        }

        $key = $this->instanceKey($vm);
        $list = $this->getSnapshotsList($vm);

        foreach ($list as $existing) {
            if ($existing['name'] === $name) {
                return false;
            }
        }

        $snapshotItem = [
            'name' => $name,
            'created_at' => now()->toIso8601String(),
            'size' => null,
        ];

        $list[] = $snapshotItem;
        $this->snapshots[$key] = $list;

        $stateBucket = [
            'status' => $this->statuses[$key] ?? $this->statuses[$vm->name] ?? null,
            'state' => $this->instanceStates[$key] ?? $this->instanceStates[$vm->name] ?? null,
            'marker' => $this->stateMarkers[$key] ?? $this->stateMarkers[$vm->name] ?? null,
        ];

        $this->snapshotStates[$key][$name] = $stateBucket;

        try {
            Cache::put("fake_driver:snapshots:{$key}", $list, 3600);
            Cache::put("fake_driver:snapshot_states:{$key}:{$name}", $stateBucket, 3600);
        } catch (\Throwable) {
        }

        return true;
    }

    /**
     * @return list<array{name: string, created_at: string, size: int|null}>
     */
    #[Override]
    public function listSnapshots(VirtualMachine $vm): array
    {
        if ($this->shouldFail('listSnapshots')) {
            return [];
        }

        $items = $this->getSnapshotsList($vm);
        $normalized = [];

        foreach ($items as $item) {
            $normalized[] = [
                'name' => (string) $item['name'],
                'created_at' => (string) $item['created_at'],
                'size' => isset($item['size']) ? (int) $item['size'] : null,
            ];
        }

        return $normalized;
    }

    #[Override]
    public function restoreSnapshot(VirtualMachine $vm, string $name): bool
    {
        if ($this->shouldFail('restoreSnapshot')) {
            return false;
        }

        $key = $this->instanceKey($vm);
        $list = $this->getSnapshotsList($vm);

        $found = false;
        foreach ($list as $snapshot) {
            if ($snapshot['name'] === $name) {
                $found = true;
                break;
            }
        }

        if (! $found) {
            return false;
        }

        $this->restoredSnapshots[$key] = $name;
        $this->restoredSnapshots[$vm->name] = $name;

        try {
            Cache::put("fake_driver:restored_snapshot:{$key}", $name, 3600);
            Cache::put("fake_driver:restored_snapshot:{$vm->name}", $name, 3600);
        } catch (\Throwable) {
        }

        $stateBucket = $this->snapshotStates[$key][$name] ?? $this->snapshotStates[$vm->name][$name] ?? null;

        if ($stateBucket === null) {
            try {
                $cachedBucket = Cache::get("fake_driver:snapshot_states:{$key}:{$name}")
                    ?? Cache::get("fake_driver:snapshot_states:{$vm->name}:{$name}");
                if (is_array($cachedBucket)) {
                    /** @var array{status: string|null, state: array<string, mixed>|null, marker: string|null} $cachedBucket */
                    $stateBucket = $cachedBucket;
                }
            } catch (\Throwable) {
            }
        }

        if ($stateBucket !== null) {
            if ($stateBucket['status'] !== null) {
                $this->statuses[$vm->name] = $stateBucket['status'];
                try {
                    Cache::put("fake_driver:status:{$vm->name}", $stateBucket['status'], 3600);
                } catch (\Throwable) {
                }
            } else {
                unset($this->statuses[$vm->name]);
                try {
                    Cache::forget("fake_driver:status:{$vm->name}");
                } catch (\Throwable) {
                }
            }

            if ($stateBucket['state'] !== null) {
                $this->instanceStates[$vm->name] = $stateBucket['state'];
                try {
                    Cache::put("fake_driver:state:{$vm->name}", $stateBucket['state'], 3600);
                } catch (\Throwable) {
                }
            } else {
                unset($this->instanceStates[$vm->name]);
                try {
                    Cache::forget("fake_driver:state:{$vm->name}");
                } catch (\Throwable) {
                }
            }

            if ($stateBucket['marker'] !== null) {
                $this->stateMarkers[$key] = $stateBucket['marker'];
                $this->stateMarkers[$vm->name] = $stateBucket['marker'];
                try {
                    Cache::put("fake_driver:marker:{$key}", $stateBucket['marker'], 3600);
                    Cache::put("fake_driver:marker:{$vm->name}", $stateBucket['marker'], 3600);
                } catch (\Throwable) {
                }
            } else {
                unset($this->stateMarkers[$key], $this->stateMarkers[$vm->name]);
                try {
                    Cache::forget("fake_driver:marker:{$key}");
                    Cache::forget("fake_driver:marker:{$vm->name}");
                } catch (\Throwable) {
                }
            }
        } else {
            unset($this->stateMarkers[$key], $this->stateMarkers[$vm->name]);
            try {
                Cache::forget("fake_driver:marker:{$key}");
                Cache::forget("fake_driver:marker:{$vm->name}");
            } catch (\Throwable) {
            }
        }

        return true;
    }

    #[Override]
    public function deleteSnapshot(VirtualMachine $vm, string $name): bool
    {
        if ($this->shouldFail('deleteSnapshot')) {
            return false;
        }

        $key = $this->instanceKey($vm);
        $list = $this->getSnapshotsList($vm);
        $filtered = [];
        $deleted = false;

        foreach ($list as $snapshot) {
            if ($snapshot['name'] === $name) {
                $deleted = true;

                continue;
            }
            $filtered[] = $snapshot;
        }

        if (! $deleted) {
            return false;
        }

        $this->snapshots[$key] = $filtered;
        unset($this->snapshots[$vm->name]);
        unset($this->snapshotStates[$key][$name], $this->snapshotStates[$vm->name][$name]);

        try {
            Cache::put("fake_driver:snapshots:{$key}", $filtered, 3600);
            Cache::forget("fake_driver:snapshots:{$vm->name}");
            Cache::forget("fake_driver:snapshot_states:{$key}:{$name}");
            Cache::forget("fake_driver:snapshot_states:{$vm->name}:{$name}");
        } catch (\Throwable) {
        }

        return true;
    }

    public function getRestoredSnapshot(VirtualMachine $vm): ?string
    {
        $key = $this->instanceKey($vm);

        if (isset($this->restoredSnapshots[$key])) {
            return $this->restoredSnapshots[$key];
        }

        if (isset($this->restoredSnapshots[$vm->name])) {
            return $this->restoredSnapshots[$vm->name];
        }

        try {
            $cached = Cache::get("fake_driver:restored_snapshot:{$key}")
                ?? Cache::get("fake_driver:restored_snapshot:{$vm->name}");
            if (is_string($cached)) {
                return $cached;
            }
        } catch (\Throwable) {
        }

        return null;
    }

    public function setStateMarker(VirtualMachine $vm, ?string $marker): self
    {
        $key = $this->instanceKey($vm);
        $this->stateMarkers[$key] = $marker;
        $this->stateMarkers[$vm->name] = $marker;

        try {
            if ($marker !== null) {
                Cache::put("fake_driver:marker:{$key}", $marker, 3600);
                Cache::put("fake_driver:marker:{$vm->name}", $marker, 3600);
            } else {
                Cache::forget("fake_driver:marker:{$key}");
                Cache::forget("fake_driver:marker:{$vm->name}");
            }
        } catch (\Throwable) {
        }

        return $this;
    }

    public function getStateMarker(VirtualMachine $vm): ?string
    {
        $key = $this->instanceKey($vm);

        if (array_key_exists($key, $this->stateMarkers)) {
            return $this->stateMarkers[$key];
        }

        if (array_key_exists($vm->name, $this->stateMarkers)) {
            return $this->stateMarkers[$vm->name];
        }

        try {
            $cached = Cache::get("fake_driver:marker:{$key}")
                ?? Cache::get("fake_driver:marker:{$vm->name}");
            if (is_string($cached)) {
                return $cached;
            }
        } catch (\Throwable) {
        }

        return null;
    }

    #[Override]
    public function createConsoleSession(VirtualMachine $vm, string $type = 'console', int $width = 80, int $height = 24): array
    {
        if ($vm->instance_type === 'container' && $type === 'vga') {
            throw new \InvalidArgumentException('Containers do not support graphical VGA console.');
        }

        if ($this->shouldFail('createConsoleSession')) {
            throw new \RuntimeException(sprintf('Fake driver forced failure creating console session for VM %s', $vm->name));
        }

        $opId = 'fake-op-console-'.$vm->id;

        return [
            'operation_id' => $opId,
            'websocket_path' => sprintf('/1.0/operations/%s/websocket?secret=fake-sec-data-%d', $opId, $vm->id),
            'control_path' => sprintf('/1.0/operations/%s/websocket?secret=fake-sec-ctrl-%d', $opId, $vm->id),
            'type' => $type,
            'driver' => 'console',
        ];
    }

    #[Override]
    public function createExecSession(VirtualMachine $vm, int $width = 80, int $height = 24): array
    {
        if ($this->shouldFail('createExecSession') || $this->shouldFail("createExecSession:{$vm->name}")) {
            throw new \RuntimeException(sprintf("VM agent isn't currently running for instance %s", $vm->name));
        }

        $agentRunning = $this->instanceStates[$vm->name]['agent_running'] ?? null;
        if ($agentRunning === null) {
            try {
                $cachedState = Cache::get("fake_driver:state:{$vm->name}");
                if (is_array($cachedState) && array_key_exists('agent_running', $cachedState)) {
                    $agentRunning = $cachedState['agent_running'];
                }
            } catch (\Throwable) {
            }
        }

        if ($agentRunning === false) {
            throw new \RuntimeException(sprintf("VM agent isn't currently running for instance %s", $vm->name));
        }

        $opId = 'fake-op-exec-'.$vm->id;

        return [
            'operation_id' => $opId,
            'websocket_path' => sprintf('/1.0/operations/%s/websocket?secret=fake-sec-exec-data-%d', $opId, $vm->id),
            'control_path' => sprintf('/1.0/operations/%s/websocket?secret=fake-sec-exec-ctrl-%d', $opId, $vm->id),
            'type' => 'console',
            'driver' => 'exec',
        ];
    }

    #[Override]
    public function cancelOperation(VirtualMachine $vm, string $operationId): bool
    {
        $this->cancelledOperations[] = $operationId;

        return true;
    }

    #[Override]
    public function getHostResources(Host $host): HostResourcesDTO
    {
        if (isset($this->actionCallbacks['getHostResources'])) {
            /** @var HostResourcesDTO $res */
            $res = ($this->actionCallbacks['getHostResources'])($host);

            return $res;
        }

        if ($this->shouldFail('getHostResources')) {
            throw new \RuntimeException(sprintf('Fake driver forced failure getting host resources for %s', $host->name));
        }

        if ($this->hostResources !== null) {
            return $this->hostResources;
        }

        return new HostResourcesDTO(
            cpuCores: $host->total_cpu_cores > 0 ? $host->total_cpu_cores : 32,
            ramMb: $host->total_ram_mb > 0 ? $host->total_ram_mb : 65536,
            storageGb: $host->total_storage_gb > 0 ? $host->total_storage_gb : 2000,
            storagePool: 'default',
        );
    }

    /**
     * @return array<string, RawInstanceMetricsDTO>
     */
    #[Override]
    public function getHostInstanceMetrics(Host $host, int $timeoutSeconds = 10): array
    {
        $this->metricsRequestCount++;

        if ($this->shouldFail('getHostInstanceMetrics') || $this->shouldFail("getHostInstanceMetrics:{$host->id}")) {
            throw new \RuntimeException(sprintf('Fake driver forced failure getting host metrics for %s', $host->name));
        }

        if (isset($this->actionCallbacks['getHostInstanceMetrics'])) {
            /** @var array<string, RawInstanceMetricsDTO> $res */
            $res = ($this->actionCallbacks['getHostInstanceMetrics'])($host, $timeoutSeconds);

            return $res;
        }

        if (isset($this->hostMetricsOverrides[$host->id])) {
            $override = $this->hostMetricsOverrides[$host->id];
            if (is_string($override)) {
                return app(OpenMetricsParser::class)->parse($override);
            }

            return $override;
        }

        $vms = VirtualMachine::query()
            ->where('host_id', $host->id)
            ->where('status', 'running')
            ->get(['id', 'name', 'incus_project', 'instance_type', 'cpu_cores', 'ram_mb', 'disk_gb']);

        $result = [];

        foreach ($vms as $vm) {
            if (strtoupper($this->getInstanceStatus($vm)) !== 'RUNNING') {
                continue;
            }

            $agentRunning = true;
            if ($vm->instance_type !== 'container') {
                if (array_key_exists($vm->name, $this->instanceStates)) {
                    $agentRunning = (bool) ($this->instanceStates[$vm->name]['agent_running'] ?? true);
                } else {
                    try {
                        $cachedState = Cache::get("fake_driver:state:{$vm->name}");
                        if (is_array($cachedState) && array_key_exists('agent_running', $cachedState)) {
                            $agentRunning = (bool) $cachedState['agent_running'];
                        }
                    } catch (\Throwable) {
                    }
                }
            }

            $step = ($this->simulatedScrapeCounts[$vm->id] ?? 0) + 1;
            $this->simulatedScrapeCounts[$vm->id] = $step;

            $cpuCores = max(1, (int) $vm->cpu_cores);
            $cpuBusy = round((float) $step * 12.5 * (float) $cpuCores, 2);
            $memTotal = $agentRunning ? ((int) $vm->ram_mb * 1024 * 1024) : null;
            $memAvail = $memTotal !== null ? (int) round((float) $memTotal * 0.65) : null;
            $fsTotal = $agentRunning ? ((int) $vm->disk_gb * 1024 * 1024 * 1024) : null;
            $fsAvail = $fsTotal !== null ? (int) round((float) $fsTotal * 0.75) : null;
            $diskRead = $step * 1048576;
            $diskWrite = $step * 524288;
            $netRx = $step * 262144;
            $netTx = $step * 131072;

            $project = $vm->incus_project !== '' ? $vm->incus_project : 'default';
            $instanceType = $vm->instance_type === 'container' ? 'container' : 'virtual-machine';

            $result["{$project}/{$vm->name}"] = new RawInstanceMetricsDTO(
                project: $project,
                name: $vm->name,
                instanceType: $instanceType,
                cpuBusySecondsTotal: $cpuBusy,
                effectiveCpus: $cpuCores,
                memoryTotalBytes: $memTotal,
                memoryAvailableBytes: $memAvail,
                filesystemTotalBytes: $fsTotal,
                filesystemAvailBytes: $fsAvail,
                diskReadBytesTotal: $diskRead,
                diskWriteBytesTotal: $diskWrite,
                netRxBytesTotal: $netRx,
                netTxBytesTotal: $netTx,
                procsTotal: $agentRunning ? 42 : 0,
            );
        }

        return $result;
    }

    /**
     * @var array<string, InstanceResourceSpec>
     */
    public array $instanceResourceSpecs = [];

    /**
     * @var array<string, string>
     */
    public array $instanceStorageDrivers = [];

    /**
     * @var array<string, string|null>
     */
    public array $instanceNicTypes = [];

    /**
     * @var array<string, bool>
     */
    public array $unreachableHosts = [];

    /**
     * @var array<string, array<string, ResourceUpdateVerdict>>
     */
    public array $assessmentVerdictOverrides = [];

    /**
     * @var array<string, array<string, string>>
     */
    public array $assessmentReasonOverrides = [];

    /**
     * @var array<string, int|bool>
     */
    public array $etagConflicts = [];

    /**
     * @var array<string, ResourceUpdateResult>
     */
    public array $forcedApplyResults = [];

    /**
     * @var array<string, array{spec: InstanceResourceSpec, result: ResourceUpdateResult|null}>
     */
    public array $partialApplyConfigs = [];

    /**
     * @var array<string, array{polls_before_complete: int, terminal_state: OperationState, error_code: ?string, safe_message: ?string}>
     */
    public array $asyncApplyConfigs = [];

    /**
     * @var array<string, array{vm_key: string, polls_remaining: int, terminal_state: OperationState, error_code: ?string, safe_message: ?string, target: ?InstanceResourceSpec}>
     */
    private array $asyncOperations = [];

    /**
     * @var array<string, OperationStatus>
     */
    public array $forcedOperationStatuses = [];

    /**
     * @var list<array{vm: string, target: InstanceResourceSpec}>
     */
    public array $appliedResourceUpdates = [];

    private int $operationSequence = 0;

    public function setInstanceResourceSpec(VirtualMachine|string $vm, InstanceResourceSpec $spec): self
    {
        $key = $vm instanceof VirtualMachine ? $this->instanceKey($vm) : $vm;
        $this->instanceResourceSpecs[$key] = $spec;

        return $this;
    }

    public function setInstanceStorageDriver(VirtualMachine|string $vm, string $driver): self
    {
        $key = $vm instanceof VirtualMachine ? $this->instanceKey($vm) : $vm;
        $this->instanceStorageDrivers[$key] = $driver;

        return $this;
    }

    public function setInstanceNicType(VirtualMachine|string $vm, ?string $nicType): self
    {
        $key = $vm instanceof VirtualMachine ? $this->instanceKey($vm) : $vm;
        $this->instanceNicTypes[$key] = $nicType;

        return $this;
    }

    public function setHostUnreachable(Host|VirtualMachine|string|int $target, bool $unreachable = true): self
    {
        $key = match (true) {
            $target instanceof Host => sprintf('host:%d', $target->id),
            $target instanceof VirtualMachine => $this->instanceKey($target),
            is_int($target) => sprintf('host:%d', $target),
            default => $target,
        };

        $this->unreachableHosts[$key] = $unreachable;

        return $this;
    }

    public function setAssessmentVerdict(
        VirtualMachine|string $vm,
        ResourceKind|string $resource,
        ResourceUpdateVerdict $verdict,
        ?string $reason = null,
    ): self {
        $key = $vm instanceof VirtualMachine ? $this->instanceKey($vm) : $vm;
        $resourceKey = $resource instanceof ResourceKind ? $resource->value : $resource;

        $this->assessmentVerdictOverrides[$key][$resourceKey] = $verdict;
        if ($reason !== null) {
            $this->assessmentReasonOverrides[$key][$resourceKey] = $reason;
        }

        return $this;
    }

    /**
     * @param  array<string, ResourceUpdateVerdict>  $verdicts
     * @param  array<string, string>  $reasons
     */
    public function setAssessmentOverrides(VirtualMachine|string $vm, array $verdicts, array $reasons = []): self
    {
        $key = $vm instanceof VirtualMachine ? $this->instanceKey($vm) : $vm;
        $this->assessmentVerdictOverrides[$key] = $verdicts;
        $this->assessmentReasonOverrides[$key] = $reasons;

        return $this;
    }

    public function setEtagConflict(VirtualMachine|string $vm, int|bool $conflict = true): self
    {
        $key = $vm instanceof VirtualMachine ? $this->instanceKey($vm) : $vm;
        $this->etagConflicts[$key] = $conflict;

        return $this;
    }

    public function setApplyResult(VirtualMachine|string $vm, ResourceUpdateResult $result): self
    {
        $key = $vm instanceof VirtualMachine ? $this->instanceKey($vm) : $vm;
        $this->forcedApplyResults[$key] = $result;

        return $this;
    }

    public function setApplyOutcome(
        VirtualMachine|string $vm,
        ResourceUpdateStatus $status,
        ?string $errorCode = null,
        ?string $safeMessage = null,
        ?string $operationId = null,
    ): self {
        return $this->setApplyResult($vm, new ResourceUpdateResult(
            status: $status,
            operationId: $operationId,
            errorCode: $errorCode,
            safeMessage: $safeMessage,
        ));
    }

    public function setPartialApplySpec(
        VirtualMachine|string $vm,
        InstanceResourceSpec $partialSpec,
        ?ResourceUpdateResult $result = null,
    ): self {
        $key = $vm instanceof VirtualMachine ? $this->instanceKey($vm) : $vm;
        $this->partialApplyConfigs[$key] = [
            'spec' => $partialSpec,
            'result' => $result,
        ];

        return $this;
    }

    public function setAsyncApplyLatency(
        VirtualMachine|string $vm,
        int $pollsBeforeComplete,
        OperationState $terminalState = OperationState::Succeeded,
        ?string $errorCode = null,
        ?string $safeMessage = null,
    ): self {
        $key = $vm instanceof VirtualMachine ? $this->instanceKey($vm) : $vm;
        $this->asyncApplyConfigs[$key] = [
            'polls_before_complete' => max(0, $pollsBeforeComplete),
            'terminal_state' => $terminalState,
            'error_code' => $errorCode,
            'safe_message' => $safeMessage,
        ];

        return $this;
    }

    public function setOperationStatus(string $operationId, OperationStatus $status): self
    {
        $this->forcedOperationStatuses[$operationId] = $status;

        return $this;
    }

    #[Override]
    public function getInstanceResourceSpec(VirtualMachine $vm): InstanceResourceSpec
    {
        if (isset($this->actionCallbacks['getInstanceResourceSpec'])) {
            /** @var InstanceResourceSpec $spec */
            $spec = ($this->actionCallbacks['getInstanceResourceSpec'])($vm);

            return $spec;
        }

        if ($this->isHostUnreachableFor($vm) || $this->shouldFail('getInstanceResourceSpec')) {
            throw new DriverUnavailableException(sprintf('Fake hypervisor host is unreachable for instance %s.', $vm->name));
        }

        return $this->resolveObservedSpec($vm);
    }

    #[Override]
    public function assessResourceUpdate(VirtualMachine $vm, InstanceResourceSpec $target): ResourceUpdateAssessment
    {
        if (isset($this->actionCallbacks['assessResourceUpdate'])) {
            /** @var ResourceUpdateAssessment $assessment */
            $assessment = ($this->actionCallbacks['assessResourceUpdate'])($vm, $target);

            return $assessment;
        }

        if ($this->isHostUnreachableFor($vm) || $this->shouldFail('assessResourceUpdate')) {
            throw new DriverUnavailableException(sprintf('Fake hypervisor host is unreachable for instance %s.', $vm->name));
        }

        $observed = $this->resolveObservedSpec($vm);
        $key = $this->instanceKey($vm);

        $storageDriver = $this->instanceStorageDrivers[$key]
            ?? $this->instanceStorageDrivers[$vm->name]
            ?? 'zfs';

        $nicType = array_key_exists($key, $this->instanceNicTypes)
            ? $this->instanceNicTypes[$key]
            : (array_key_exists($vm->name, $this->instanceNicTypes) ? $this->instanceNicTypes[$vm->name] : 'routed');

        $base = (new ResourceCapabilityMatrix)->assess(
            observed: $observed,
            target: $target,
            vmState: $this->getInstanceStatus($vm),
            storageDriver: $storageDriver,
            nicType: $nicType,
        );

        $verdictOverrides = $this->assessmentVerdictOverrides[$key]
            ?? $this->assessmentVerdictOverrides[$vm->name]
            ?? $this->assessmentVerdictOverrides['*']
            ?? [];

        $reasonOverrides = $this->assessmentReasonOverrides[$key]
            ?? $this->assessmentReasonOverrides[$vm->name]
            ?? $this->assessmentReasonOverrides['*']
            ?? [];

        if ($verdictOverrides === [] && $reasonOverrides === []) {
            return $base;
        }

        return new ResourceUpdateAssessment(
            verdicts: array_merge($base->verdicts, $verdictOverrides),
            reasons: array_merge($base->reasons, $reasonOverrides),
        );
    }

    #[Override]
    public function applyResourceUpdate(VirtualMachine $vm, InstanceResourceSpec $target): ResourceUpdateResult
    {
        if (isset($this->actionCallbacks['applyResourceUpdate'])) {
            /** @var ResourceUpdateResult $result */
            $result = ($this->actionCallbacks['applyResourceUpdate'])($vm, $target);

            return $result;
        }

        if ($this->isHostUnreachableFor($vm)) {
            return new ResourceUpdateResult(
                status: ResourceUpdateStatus::Failed,
                errorCode: 'DRIVER_UNAVAILABLE',
                safeMessage: sprintf('Fake hypervisor host is unreachable for instance %s.', $vm->name),
            );
        }

        $key = $this->instanceKey($vm);

        if ($this->consumeEtagConflict($key, $vm->name)) {
            return new ResourceUpdateResult(
                status: ResourceUpdateStatus::Rejected,
                errorCode: 'CONCURRENT_MODIFICATION',
                safeMessage: 'Simulated concurrent modification (ETag mismatch).',
            );
        }

        $forced = $this->forcedApplyResults[$key]
            ?? $this->forcedApplyResults[$vm->name]
            ?? $this->forcedApplyResults['*']
            ?? null;
        if ($forced !== null) {
            return $forced;
        }

        if ($this->shouldFail('applyResourceUpdate')) {
            return new ResourceUpdateResult(
                status: ResourceUpdateStatus::Failed,
                errorCode: 'HYPERVISOR_OPERATION_FAILED',
                safeMessage: sprintf('Fake driver forced failure applying resource update for %s.', $vm->name),
            );
        }

        $partial = $this->partialApplyConfigs[$key]
            ?? $this->partialApplyConfigs[$vm->name]
            ?? null;
        if ($partial !== null) {
            $this->instanceResourceSpecs[$key] = $partial['spec'];

            return $partial['result'] ?? new ResourceUpdateResult(
                status: ResourceUpdateStatus::Failed,
                errorCode: 'PARTIAL_APPLY',
                safeMessage: 'Simulated partial resource update application.',
            );
        }

        $async = $this->asyncApplyConfigs[$key]
            ?? $this->asyncApplyConfigs[$vm->name]
            ?? $this->asyncApplyConfigs['*']
            ?? null;
        if ($async !== null) {
            $operationId = sprintf('fake-op-resize-%s-%d', $vm->name, ++$this->operationSequence);
            $this->asyncOperations[$operationId] = [
                'vm_key' => $key,
                'polls_remaining' => $async['polls_before_complete'],
                'terminal_state' => $async['terminal_state'],
                'error_code' => $async['error_code'],
                'safe_message' => $async['safe_message'],
                'target' => $target,
            ];

            return new ResourceUpdateResult(
                status: ResourceUpdateStatus::Accepted,
                operationId: $operationId,
            );
        }

        $this->instanceResourceSpecs[$key] = $target;
        $this->appliedResourceUpdates[] = ['vm' => $key, 'target' => $target];

        return new ResourceUpdateResult(
            status: ResourceUpdateStatus::Applied,
        );
    }

    #[Override]
    public function getOperationStatus(VirtualMachine $vm, string $operationId): OperationStatus
    {
        if (isset($this->actionCallbacks['getOperationStatus'])) {
            /** @var OperationStatus $status */
            $status = ($this->actionCallbacks['getOperationStatus'])($vm, $operationId);

            return $status;
        }

        if (isset($this->forcedOperationStatuses[$operationId])) {
            return $this->forcedOperationStatuses[$operationId];
        }

        if (! isset($this->asyncOperations[$operationId])) {
            return new OperationStatus(
                state: OperationState::Unknown,
                errorCode: 'OPERATION_NOT_FOUND',
                safeMessage: sprintf('Operation "%s" was not found.', $operationId),
            );
        }

        $operation = $this->asyncOperations[$operationId];
        if ($operation['polls_remaining'] > 0) {
            $this->asyncOperations[$operationId]['polls_remaining'] = $operation['polls_remaining'] - 1;

            return new OperationStatus(state: OperationState::Running);
        }

        if ($operation['terminal_state'] === OperationState::Succeeded && $operation['target'] !== null) {
            $this->instanceResourceSpecs[$operation['vm_key']] = $operation['target'];
            $this->appliedResourceUpdates[] = ['vm' => $operation['vm_key'], 'target' => $operation['target']];
        }

        return new OperationStatus(
            state: $operation['terminal_state'],
            errorCode: $operation['error_code'],
            safeMessage: $operation['safe_message'],
        );
    }

    private function resolveObservedSpec(VirtualMachine $vm): InstanceResourceSpec
    {
        $key = $this->instanceKey($vm);

        if (isset($this->instanceResourceSpecs[$key])) {
            return $this->instanceResourceSpecs[$key];
        }

        if (isset($this->instanceResourceSpecs[$vm->name])) {
            return $this->instanceResourceSpecs[$vm->name];
        }

        return new InstanceResourceSpec(
            cpuCores: max(1, (int) $vm->cpu_cores),
            ramMb: max(512, (int) $vm->ram_mb),
            diskGb: max(1, (int) $vm->disk_gb),
            instanceType: $vm->instance_type === 'container' ? 'container' : 'virtual-machine',
            network: null,
        );
    }

    private function isHostUnreachableFor(VirtualMachine $vm): bool
    {
        $key = $this->instanceKey($vm);
        $hostKey = $vm->host_id !== null ? sprintf('host:%d', $vm->host_id) : '';

        return ($this->unreachableHosts['all'] ?? false)
            || ($this->unreachableHosts[$key] ?? false)
            || ($this->unreachableHosts[$vm->name] ?? false)
            || ($hostKey !== '' && ($this->unreachableHosts[$hostKey] ?? false));
    }

    private function consumeEtagConflict(string $key, string $vmName): bool
    {
        foreach ([$key, $vmName, '*'] as $candidate) {
            if (! array_key_exists($candidate, $this->etagConflicts)) {
                continue;
            }

            $setting = $this->etagConflicts[$candidate];
            if ($setting === true) {
                return true;
            }
            if (is_int($setting) && $setting > 0) {
                $this->etagConflicts[$candidate] = $setting - 1;

                return true;
            }
        }

        return false;
    }
}
