<?php

declare(strict_types=1);

namespace App\Events;

use App\Http\Resources\VirtualMachineResource;
use App\Models\VirtualMachine;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Real-time WebSocket event broadcast when a virtual machine status or network changes.
 */
final class VirtualMachineStatusUpdated implements ShouldBroadcast
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
            $failureReason = is_array($virtualMachine->metadata) && isset($virtualMachine->metadata['failure_reason']) && is_string($virtualMachine->metadata['failure_reason'])
                ? $virtualMachine->metadata['failure_reason']
                : null;

            $this->vm = [
                'id' => (int) $virtualMachine->id,
                'status' => (string) $virtualMachine->status,
                'failure_reason' => $failureReason,
                'network_status' => $virtualMachine->network_status,
                'ipv4' => $virtualMachine->ipv4_address,
                'ipv4_address' => $virtualMachine->ipv4_address,
                'updated_at' => $virtualMachine->updated_at?->toISOString() ?? now()->toISOString(),
                'is_delta' => true,
            ];

            return;
        }

        $vmToResolve = clone $virtualMachine;
        $vmToResolve->loadMissing(['host', 'primaryAllocation.pool', 'user']);

        /** @var array<string, mixed> $resolved */
        $resolved = (new VirtualMachineResource($vmToResolve))->resolve();

        unset($resolved['host']);

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
            new PrivateChannel('users.'.$this->userId),
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
