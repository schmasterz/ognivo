<?php

declare(strict_types=1);

namespace App\Services\Hypervisor\Drivers;

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
     * @var array<string, callable>
     */
    public array $actionCallbacks = [];

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

    #[Override]
    public function deleteInstance(VirtualMachine $vm): bool
    {
        if ($this->shouldFail('deleteInstance')) {
            return false;
        }

        $this->deletedInstances[] = $vm->name;

        try {
            Cache::put("fake_driver:deleted:{$vm->name}", true, 3600);
            Cache::forget("fake_driver:state:{$vm->name}");
            Cache::forget("fake_driver:status:{$vm->name}");
        } catch (\Throwable) {
        }

        unset(
            $this->snapshots[$vm->name],
            $this->snapshotStates[$vm->name],
            $this->restoredSnapshots[$vm->name],
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
     * @var array<string, list<array{name: string, created_at: string, stateful: bool, size: int|null}>>
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
        if (isset($this->instanceStates[$vm->name])) {
            $state = $this->instanceStates[$vm->name];
            if (isset($this->restoredSnapshots[$vm->name])) {
                $state['restored_snapshot'] = $this->restoredSnapshots[$vm->name];
            }

            return $state;
        }

        try {
            $cached = Cache::get("fake_driver:state:{$vm->name}");
            if (is_array($cached)) {
                /** @var array<string, mixed> $cached */
                if (isset($this->restoredSnapshots[$vm->name])) {
                    $cached['restored_snapshot'] = $this->restoredSnapshots[$vm->name];
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

        if (isset($this->restoredSnapshots[$vm->name])) {
            $state['restored_snapshot'] = $this->restoredSnapshots[$vm->name];
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
        if ($this->shouldFail['deleteProject'] ?? false) {
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

    #[Override]
    public function createSnapshot(VirtualMachine $vm, string $name, bool $stateful = false): bool
    {
        if ($this->shouldFail['createSnapshot'] ?? false) {
            return false;
        }

        if ($name === '') {
            return false;
        }

        foreach ($this->snapshots[$vm->name] ?? [] as $existing) {
            if ($existing['name'] === $name) {
                return false;
            }
        }

        $this->snapshots[$vm->name][] = [
            'name' => $name,
            'created_at' => now()->toIso8601String(),
            'stateful' => $stateful,
            'size' => null,
        ];

        $this->snapshotStates[$vm->name][$name] = [
            'status' => $this->statuses[$vm->name] ?? null,
            'state' => $this->instanceStates[$vm->name] ?? null,
            'marker' => $this->stateMarkers[$vm->name] ?? null,
        ];

        return true;
    }

    /**
     * @return list<array{name: string, created_at: string, stateful: bool, size: int|null}>
     */
    #[Override]
    public function listSnapshots(VirtualMachine $vm): array
    {
        if ($this->shouldFail['listSnapshots'] ?? false) {
            return [];
        }

        $items = $this->snapshots[$vm->name] ?? [];
        $normalized = [];

        foreach ($items as $item) {
            $normalized[] = [
                'name' => (string) $item['name'],
                'created_at' => (string) $item['created_at'],
                'stateful' => (bool) $item['stateful'],
                'size' => isset($item['size']) ? (int) $item['size'] : null,
            ];
        }

        return $normalized;
    }

    #[Override]
    public function restoreSnapshot(VirtualMachine $vm, string $name): bool
    {
        if ($this->shouldFail['restoreSnapshot'] ?? false) {
            return false;
        }

        $found = false;
        foreach ($this->snapshots[$vm->name] ?? [] as $snapshot) {
            if ($snapshot['name'] === $name) {
                $found = true;
                break;
            }
        }

        if (! $found) {
            return false;
        }

        $this->restoredSnapshots[$vm->name] = $name;

        if (isset($this->snapshotStates[$vm->name][$name])) {
            $saved = $this->snapshotStates[$vm->name][$name];

            if ($saved['status'] !== null) {
                $this->statuses[$vm->name] = $saved['status'];
            } else {
                unset($this->statuses[$vm->name]);
            }

            if ($saved['state'] !== null) {
                $this->instanceStates[$vm->name] = $saved['state'];
            } else {
                unset($this->instanceStates[$vm->name]);
            }

            if ($saved['marker'] !== null) {
                $this->stateMarkers[$vm->name] = $saved['marker'];
            } else {
                unset($this->stateMarkers[$vm->name]);
            }
        } else {
            unset($this->stateMarkers[$vm->name]);
        }

        return true;
    }

    #[Override]
    public function deleteSnapshot(VirtualMachine $vm, string $name): bool
    {
        if ($this->shouldFail['deleteSnapshot'] ?? false) {
            return false;
        }

        $existing = $this->snapshots[$vm->name] ?? [];
        $filtered = [];
        $deleted = false;

        foreach ($existing as $snapshot) {
            if ($snapshot['name'] === $name) {
                $deleted = true;

                continue;
            }
            $filtered[] = $snapshot;
        }

        if (! $deleted) {
            return false;
        }

        $this->snapshots[$vm->name] = $filtered;
        unset($this->snapshotStates[$vm->name][$name]);

        return true;
    }

    public function getRestoredSnapshot(VirtualMachine $vm): ?string
    {
        return $this->restoredSnapshots[$vm->name] ?? null;
    }

    public function setStateMarker(VirtualMachine $vm, ?string $marker): self
    {
        $this->stateMarkers[$vm->name] = $marker;

        return $this;
    }

    public function getStateMarker(VirtualMachine $vm): ?string
    {
        return $this->stateMarkers[$vm->name] ?? null;
    }

    #[Override]
    public function createConsoleSession(VirtualMachine $vm, string $type = 'console'): array
    {
        if ($vm->instance_type === 'container' && $type === 'vga') {
            throw new \InvalidArgumentException('Containers do not support graphical VGA console.');
        }

        if ($this->shouldFail['createConsoleSession'] ?? false) {
            throw new \RuntimeException(sprintf('Fake driver forced failure creating console session for VM %s', $vm->name));
        }

        $opId = 'fake-op-console-'.$vm->id;

        return [
            'operation_id' => $opId,
            'websocket_path' => sprintf('/1.0/operations/%s/websocket?secret=fake-sec-data-%d', $opId, $vm->id),
            'control_path' => sprintf('/1.0/operations/%s/websocket?secret=fake-sec-ctrl-%d', $opId, $vm->id),
            'type' => $type,
        ];
    }
}
