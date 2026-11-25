<?php

declare(strict_types=1);

namespace App\Services\Hypervisor;

use App\Models\DriftReport;
use App\Models\Host;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\IpManagementService;
use App\States\Vm\Deleted;
use App\States\Vm\Running;
use App\States\Vm\Stopped;
use App\States\Vm\Suspended;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Service to process Incus lifecycle events and synchronize database state with broadcasts.
 */
class IncusEventSyncService
{
    /**
     * Process an incoming Incus lifecycle event.
     *
     * @param  array<string, mixed>  $event
     * @return VirtualMachine|null The affected VM if found and updated
     */
    public function handleLifecycleEvent(Host $host, array $event): ?VirtualMachine
    {
        $type = (string) ($event['type'] ?? '');
        if ($type !== 'lifecycle') {
            return null;
        }

        /** @var array<string, mixed> $metadata */
        $metadata = isset($event['metadata']) && is_array($event['metadata']) ? $event['metadata'] : [];
        $action = (string) ($metadata['action'] ?? '');
        $source = (string) ($metadata['source'] ?? '');
        $project = (string) ($metadata['project'] ?? 'default');
        $instanceName = basename($source);

        if ($instanceName === '' || $action === '') {
            return null;
        }

        // Resolve VM using host, project, and instance name
        $vm = VirtualMachine::query()
            ->where('host_id', $host->id)
            ->where('name', $instanceName)
            ->where(function ($query) use ($project): void {
                $query->where('incus_project', $project);

                // Fallback: resolve tenant user with this project name
                $user = User::where('project_name', $project)->first();
                if ($user !== null) {
                    $query->orWhere('user_id', $user->id);
                }
            })
            ->first();

        if ($vm === null) {
            // Instance not present in DB
            if (in_array($action, ['instance-created', 'instance-started', 'instance-stopped'], true)) {
                DriftReport::firstOrCreate([
                    'host_id' => $host->id,
                    'instance_name' => $instanceName,
                    'incus_project' => $project,
                    'kind' => 'missing_in_db',
                    'resolved_at' => null,
                ], [
                    'actual_state' => $action === 'instance-started' ? 'running' : 'stopped',
                    'expected_state' => null,
                    'details' => ['detected_via' => 'websocket_event', 'action' => $action],
                ]);
            }

            return null;
        }

        return Cache::lock("vm:{$vm->id}", 15)->block(5, function () use ($vm, $action, $host): VirtualMachine {
            $vm->refresh();
            $currentStatus = $vm->status->getValue();

            try {
                switch ($action) {
                    case 'instance-started':
                        if ($currentStatus !== 'running') {
                            $vm->status->transitionTo(Running::class);
                            $vm->save();
                            Log::info(sprintf('Incus event: VM [%s] transitioned to Running on host [%s].', $vm->name, $host->name));
                        }
                        break;

                    case 'instance-stopped':
                        if ($currentStatus !== 'stopped') {
                            $vm->status->transitionTo(Stopped::class);
                            $vm->save();
                            Log::info(sprintf('Incus event: VM [%s] transitioned to Stopped on host [%s].', $vm->name, $host->name));
                        }
                        Cache::forget("metrics:counters:{$vm->id}");
                        break;

                    case 'instance-deleted':
                        if (! in_array($currentStatus, ['deleted', 'provisioning', 'creating'], true)) {
                            $vm->status->transitionTo(Deleted::class);
                            $vm->save();
                            app(IpManagementService::class)->releaseIp($vm);
                            Log::info(sprintf('Incus event: VM [%s] transitioned to Deleted on host [%s].', $vm->name, $host->name));
                        }
                        Cache::forget("metrics:counters:{$vm->id}");
                        Cache::forget("metrics:totals_latest:{$vm->id}");
                        break;

                    case 'instance-paused':
                    case 'instance-suspended':
                        if ($currentStatus !== 'suspended') {
                            $vm->status->transitionTo(Suspended::class);
                            $vm->save();
                            Log::info(sprintf('Incus event: VM [%s] transitioned to Suspended on host [%s].', $vm->name, $host->name));
                        }
                        break;

                    case 'instance-created':
                        // Do NOT move to Stopped if instance is currently provisioning
                        // Let ProvisionVmJob finish its startup and network observation steps
                        if ($currentStatus !== 'provisioning' && $currentStatus !== 'stopped') {
                            $vm->status->transitionTo(Stopped::class);
                            $vm->save();
                        }
                        break;

                    default:
                        // Other lifecycle actions like renamed, updated, etc.
                        break;
                }
            } catch (\Throwable $e) {
                Log::warning(sprintf(
                    'Incus event: Invalid state transition for VM [%s] (action [%s], current status [%s]): %s',
                    $vm->name,
                    $action,
                    $currentStatus,
                    $e->getMessage()
                ), [
                    'exception' => $e,
                    'vm_id' => $vm->id,
                    'host_id' => $host->id,
                    'action' => $action,
                ]);
            }

            return $vm;
        });
    }
}
