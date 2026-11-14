<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DriftReport;
use App\Models\Host;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\Hypervisor\HypervisorManager;
use Illuminate\Support\Facades\Log;

/**
 * Service to reconcile Virtual Machines between the database and Incus hosts.
 */
class VmReconciliationService
{
    public static bool $isReconciling = false;

    public static function isReconciling(): bool
    {
        return self::$isReconciling;
    }

    public static function setReconciling(bool $reconciling): void
    {
        self::$isReconciling = $reconciling;
    }

    public function __construct(
        protected readonly HypervisorManager $hypervisorManager,
        protected readonly ?VmSnapshotService $snapshotService = null,
    ) {}

    /**
     * Reconcile instances for all active hosts or a specific host.
     *
     * @return array{hosts_checked: int, drifts_detected: int, drifts_resolved: int, snapshots_synced: int}
     */
    public function reconcile(?Host $targetHost = null): array
    {
        self::$isReconciling = true;
        try {
            $hosts = $targetHost !== null
                ? collect([$targetHost])
                : Host::query()->where('is_active', true)->whereIn('driver', ['incus', 'lxd', 'fake'])->get();

            $stats = [
                'hosts_checked' => 0,
                'drifts_detected' => 0,
                'drifts_resolved' => 0,
                'snapshots_synced' => 0,
            ];

            foreach ($hosts as $host) {
                $stats['hosts_checked']++;
                $hostStats = $this->reconcileHost($host);
                $stats['drifts_detected'] += $hostStats['drifts_detected'];
                $stats['drifts_resolved'] += $hostStats['drifts_resolved'];
                $stats['snapshots_synced'] += $hostStats['snapshots_synced'];
            }

            if ($targetHost === null) {
                $unassignedVms = VirtualMachine::query()
                    ->whereNull('host_id')
                    ->whereNotIn('status', ['deleted'])
                    ->get();

                foreach ($unassignedVms as $vm) {
                    if ($this->syncVmSnapshots($vm)) {
                        $stats['snapshots_synced']++;
                    }
                }
            }

            return $stats;
        } finally {
            self::$isReconciling = false;
        }
    }

    /**
     * Reconcile instances for a single host.
     *
     * @return array{drifts_detected: int, drifts_resolved: int, snapshots_synced: int}
     */
    public function reconcileHost(Host $host): array
    {
        $detected = 0;
        $resolved = 0;
        $snapshotsSynced = 0;

        $driver = $this->hypervisorManager->driverForHost($host);

        // Host Health Guard: verify host reachability before inspecting instances
        try {
            $hostProjects = $driver->listProjects();
        } catch (\Throwable $e) {
            Log::warning(sprintf('Host node [%s] is unreachable during reconciliation: %s', $host->name, $e->getMessage()));
            if ($host->status !== 'unreachable') {
                $host->status = 'unreachable';
                $host->save();
            }

            // Do not report false missing_on_host when the host is down
            return ['drifts_detected' => 0, 'drifts_resolved' => 0, 'snapshots_synced' => 0];
        }

        if ($host->status === 'unreachable' || $host->status === 'offline') {
            $host->status = 'ok';
            $host->save();
        }

        // Distinct projects from host and from database
        $dbProjects = VirtualMachine::query()
            ->where('host_id', $host->id)
            ->whereNotIn('status', ['deleted'])
            ->pluck('incus_project')
            ->all();

        $allProjects = array_values(array_unique(array_merge($hostProjects, $dbProjects)));

        // 1. Gather all live instances from host across projects
        /** @var array<string, array{name: string, project: string, status: string}> $hostInstances */
        $hostInstances = [];
        /** @var list<string> $failedProjects */
        $failedProjects = [];

        foreach ($allProjects as $project) {
            try {
                $instances = $driver->listInstances($project);
                foreach ($instances as $inst) {
                    $key = sprintf('%s/%s', $inst['project'], $inst['name']);
                    $hostInstances[$key] = $inst;
                }
            } catch (\Throwable $e) {
                $failedProjects[] = $project;
                Log::error(sprintf('Failed to list instances for host [%s] project [%s]: %s', $host->name, $project, $e->getMessage()));
            }
        }

        // 2. Gather all active VMs from database
        $dbVms = VirtualMachine::query()
            ->where('host_id', $host->id)
            ->whereNotIn('status', ['deleted'])
            ->get();

        /** @var array<string, VirtualMachine> $dbVmMap */
        $dbVmMap = [];
        foreach ($dbVms as $vm) {
            $key = sprintf('%s/%s', $vm->incus_project, $vm->name);
            $dbVmMap[$key] = $vm;
        }

        /** @var list<string> $userProjects */
        $userProjects = User::query()
            ->whereNotNull('project_name')
            ->where('project_name', '!=', '')
            ->pluck('project_name')
            ->all();

        /** @var array<string, true> $managedProjectMap */
        $managedProjectMap = array_fill_keys(array_merge(
            array_filter($dbProjects, 'is_string'),
            $userProjects
        ), true);

        // 3. Detect "missing_in_db": instance exists on host but not in database
        foreach ($hostInstances as $key => $hostInst) {
            if (! $this->isManagedProject($hostInst['project'], $managedProjectMap)) {
                continue;
            }

            if (! isset($dbVmMap[$key])) {
                DriftReport::updateOrCreate([
                    'host_id' => $host->id,
                    'instance_name' => $hostInst['name'],
                    'incus_project' => $hostInst['project'],
                    'kind' => 'missing_in_db',
                    'resolved_at' => null,
                ], [
                    'virtual_machine_id' => null,
                    'expected_state' => null,
                    'actual_state' => $hostInst['status'],
                    'details' => ['detected_by' => 'reconcile'],
                ]);
                $detected++;
            }
        }

        // Auto-resolve open "missing_in_db" drifts if the instance is no longer on host or is now in db
        $openMissingInDbDrifts = DriftReport::query()
            ->where('host_id', $host->id)
            ->where('kind', 'missing_in_db')
            ->whereNull('resolved_at')
            ->get();

        foreach ($openMissingInDbDrifts as $drift) {
            $driftKey = sprintf('%s/%s', $drift->incus_project, $drift->instance_name);
            $noLongerOnHost = ! isset($hostInstances[$driftKey]) && ! in_array($drift->incus_project, $failedProjects, true);
            $nowInDb = isset($dbVmMap[$driftKey]);

            if ($noLongerOnHost || $nowInDb) {
                $drift->update(['resolved_at' => now()]);
                $resolved++;
            }
        }

        // Auto-resolve open "missing_on_host" drifts if the VM is now present in $hostInstances (when project did not fail)
        $openMissingOnHostDrifts = DriftReport::query()
            ->where('host_id', $host->id)
            ->where('kind', 'missing_on_host')
            ->whereNull('resolved_at')
            ->get();

        foreach ($openMissingOnHostDrifts as $drift) {
            if (in_array($drift->incus_project, $failedProjects, true)) {
                continue;
            }

            $driftKey = sprintf('%s/%s', $drift->incus_project, $drift->instance_name);
            if (isset($hostInstances[$driftKey])) {
                $drift->update(['resolved_at' => now()]);
                $resolved++;
            }
        }

        // 4. Detect "missing_on_host": VM exists in database but not on host
        foreach ($dbVmMap as $key => $vm) {
            $status = $vm->status->getValue();
            if (in_array($status, ['provisioning', 'failed'], true)) {
                continue;
            }

            if (! isset($hostInstances[$key])) {
                if (in_array($vm->incus_project, $failedProjects, true)) {
                    continue;
                }

                DriftReport::updateOrCreate([
                    'host_id' => $host->id,
                    'virtual_machine_id' => $vm->id,
                    'instance_name' => $vm->name,
                    'incus_project' => $vm->incus_project,
                    'kind' => 'missing_on_host',
                    'resolved_at' => null,
                ], [
                    'expected_state' => $vm->status->getValue(),
                    'actual_state' => null,
                    'details' => ['detected_by' => 'reconcile'],
                ]);
                $detected++;
            } else {
                // 5. Instance exists on both: verify live status using getInstanceStatus()
                $actualStatus = $driver->getInstanceStatus($vm);
                $normalizedActual = $this->normalizeStatus($actualStatus);
                $normalizedExpected = $this->normalizeStatus($vm->status->getValue());

                if ($normalizedActual !== $normalizedExpected) {
                    DriftReport::updateOrCreate([
                        'host_id' => $host->id,
                        'virtual_machine_id' => $vm->id,
                        'instance_name' => $vm->name,
                        'incus_project' => $vm->incus_project,
                        'kind' => 'state_mismatch',
                        'resolved_at' => null,
                    ], [
                        'expected_state' => $vm->status->getValue(),
                        'actual_state' => $actualStatus,
                        'details' => ['detected_by' => 'reconcile'],
                    ]);
                    $detected++;
                } else {
                    // State matches and instance is present: resolve previous open drifts if any
                    $updated = DriftReport::query()
                        ->where('host_id', $host->id)
                        ->where('instance_name', $vm->name)
                        ->where('incus_project', $vm->incus_project)
                        ->whereNull('resolved_at')
                        ->update(['resolved_at' => now()]);

                    if ($updated > 0) {
                        $resolved += $updated;
                    }
                }
            }

            // Reconcile snapshots when instance is present on host (or when hostInstances list was empty in fake mode)
            if (isset($hostInstances[$key]) || $hostInstances === []) {
                if ($this->syncVmSnapshots($vm)) {
                    $snapshotsSynced++;
                }
            }
        }

        return [
            'drifts_detected' => $detected,
            'drifts_resolved' => $resolved,
            'snapshots_synced' => $snapshotsSynced,
        ];
    }

    /**
     * Synchronize snapshots for a single virtual machine from its hypervisor backup target.
     */
    protected function syncVmSnapshots(VirtualMachine $vm): bool
    {
        try {
            $service = $this->snapshotService ?? app(VmSnapshotService::class);
            $service->syncFromHypervisor($vm);

            return true;
        } catch (\Throwable $e) {
            Log::warning(sprintf('Failed to reconcile snapshots for VM #%d (%s): %s', $vm->id, $vm->name, $e->getMessage()));

            return false;
        }
    }

    /**
     * Determine whether an Incus/LXD project is managed by Ognivo.
     *
     * @param  array<string, true>  $managedProjectMap
     */
    protected function isManagedProject(string $project, array $managedProjectMap): bool
    {
        return isset($managedProjectMap[$project]) || str_starts_with($project, 'proj-');
    }

    /**
     * Normalize status strings for comparison.
     */
    protected function normalizeStatus(string $status): string
    {
        $s = strtolower(trim($status));

        return match ($s) {
            'running' => 'running',
            'stopped' => 'stopped',
            'frozen', 'suspended', 'paused' => 'suspended',
            'error', 'failed' => 'failed',
            'provisioning' => 'provisioning',
            'deleting' => 'deleting',
            'deleted' => 'deleted',
            default => $s,
        };
    }
}
