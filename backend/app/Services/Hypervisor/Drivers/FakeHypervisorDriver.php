<?php

declare(strict_types=1);

namespace App\Services\Hypervisor\Drivers;

use App\DTO\Host\HostResourcesDTO;
use App\Models\Host;
use App\Models\VirtualMachine;
use App\Services\Hypervisor\Contracts\HypervisorDriverInterface;
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
}
