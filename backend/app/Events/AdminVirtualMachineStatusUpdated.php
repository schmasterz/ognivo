<?php

declare(strict_types=1);

namespace App\Events;

use App\Http\Resources\HostNodeResource;
use App\Http\Resources\VirtualMachineResource;
use App\Models\VirtualMachine;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Real-time WebSocket event broadcast to admin cluster monitoring channel.
 */
final class AdminVirtualMachineStatusUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @var array<string, mixed>
     */
    public array $vm;

    public int $userId;

    public bool $isDelta;

    public function __construct(VirtualMachine $virtualMachine, bool $isDelta = false)
    {
        $this->userId = (int) $virtualMachine->user_id;
        $this->isDelta = $isDelta;

        if ($isDelta) {
            $this->vm = [
                'id' => (int) $virtualMachine->id,
                'status' => (string) $virtualMachine->status,
                'network_status' => $virtualMachine->network_status,
                'ipv4' => $virtualMachine->ipv4_address,
                'ipv4_address' => $virtualMachine->ipv4_address,
                'host' => $virtualMachine->host ? (new HostNodeResource($virtualMachine->host))->resolve() : null,
                'updated_at' => $virtualMachine->updated_at?->toISOString() ?? now()->toISOString(),
                'is_delta' => true,
            ];

            return;
        }

        $vmToResolve = clone $virtualMachine;
        $vmToResolve->loadMissing(['host', 'primaryAllocation.pool', 'user']);

        /** @var array<string, mixed> $resolved */
        $resolved = (new VirtualMachineResource($vmToResolve))->resolve();
        if ($vmToResolve->host !== null) {
            $resolved['host'] = (new HostNodeResource($vmToResolve->host))->resolve();
        }

        $resolved['is_delta'] = false;
        $this->vm = $resolved;
    }

    /**
     * Channels where the event should broadcast.
     *
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admin.workloads'),
        ];
    }

    /**
     * Broadcast event alias for frontend listener.
     */
    public function broadcastAs(): string
    {
        return 'VirtualMachineStatusUpdated';
    }
}
