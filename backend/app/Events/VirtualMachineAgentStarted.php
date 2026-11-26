<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\VirtualMachine;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Override;

/**
 * Real-time WebSocket event broadcast when the Incus guest agent starts inside a virtual machine.
 */
final class VirtualMachineAgentStarted implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public readonly VirtualMachine $vm) {}

    /**
     * Channels where the event should broadcast.
     *
     * @return list<Channel>
     */
    #[Override]
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('users.'.$this->vm->user_id),
        ];
    }

    /**
     * Broadcast event alias for frontend listener.
     */
    public function broadcastAs(): string
    {
        return 'VirtualMachineAgentStarted';
    }

    /**
     * Data payload broadcast over WebSockets.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'vm_id' => $this->vm->id,
            'status' => $this->vm->status->getValue(),
            'agent_ready' => true,
        ];
    }
}
