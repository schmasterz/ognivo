<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\AdminVirtualMachineStatusUpdated;
use App\Events\VirtualMachineStatusUpdated;
use App\Models\Host;
use App\Models\User;
use App\Models\VirtualMachine;
use App\States\Vm\Running;
use App\States\Vm\Stopped;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class VirtualMachineBroadcastingTest extends TestCase
{
    use RefreshDatabase;

    public function test_vm_creation_and_status_transitions_broadcast_websocket_event(): void
    {
        Event::fake([
            VirtualMachineStatusUpdated::class,
            AdminVirtualMachineStatusUpdated::class,
        ]);

        $user = User::factory()->create();
        $host = Host::create([
            'name' => 'test-node-ws',
            'endpoint_url' => 'https://127.0.0.1:8443',
            'driver' => 'fake',
            'is_active' => true,
        ]);

        // 1. Create VM
        $vm = VirtualMachine::create([
            'user_id' => $user->id,
            'host_id' => $host->id,
            'name' => 'ws-test-vm',
            'incus_project' => 'proj-ws-1',
            'instance_type' => 'virtual-machine',
            'status' => 'provisioning',
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'ubuntu:24.04',
        ]);

        Event::assertDispatched(VirtualMachineStatusUpdated::class, function (VirtualMachineStatusUpdated $event) use ($vm, $user): bool {
            $channels = $event->broadcastOn();
            $channelNames = array_map(fn ($c) => (string) $c, $channels);

            return $event->userId === $user->id
                && $event->vm['id'] === $vm->id
                && in_array('private-users.'.$user->id, $channelNames, true)
                && ! array_key_exists('host', $event->vm);
        });

        Event::assertDispatched(AdminVirtualMachineStatusUpdated::class, function (AdminVirtualMachineStatusUpdated $event) use ($vm): bool {
            $channels = $event->broadcastOn();
            $channelNames = array_map(fn ($c) => (string) $c, $channels);

            return $event->vm['id'] === $vm->id
                && in_array('private-admin.workloads', $channelNames, true)
                && isset($event->vm['host']['endpoint_url'])
                && $event->vm['host']['endpoint_url'] === 'https://127.0.0.1:8443';
        });

        // 2. Transition state
        $vm->status->transitionTo(Running::class);
        $vm->save();

        Event::assertDispatched(VirtualMachineStatusUpdated::class, function (VirtualMachineStatusUpdated $event) use ($vm): bool {
            return $event->vm['id'] === $vm->id && $event->vm['status'] === 'running' && ! array_key_exists('host', $event->vm);
        });

        Event::assertDispatched(AdminVirtualMachineStatusUpdated::class, function (AdminVirtualMachineStatusUpdated $event) use ($vm): bool {
            return $event->vm['id'] === $vm->id && $event->vm['status'] === 'running' && isset($event->vm['host']);
        });

        // 3. Transition to Stopped
        $vm->status->transitionTo(Stopped::class);
        $vm->save();

        Event::assertDispatched(VirtualMachineStatusUpdated::class, function (VirtualMachineStatusUpdated $event) use ($vm): bool {
            return $event->vm['id'] === $vm->id && $event->vm['status'] === 'stopped';
        });

        Event::assertDispatched(AdminVirtualMachineStatusUpdated::class, function (AdminVirtualMachineStatusUpdated $event) use ($vm): bool {
            return $event->vm['id'] === $vm->id && $event->vm['status'] === 'stopped';
        });
    }
}
