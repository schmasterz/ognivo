<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\VmSnapshot;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Real-time WebSocket event broadcast when a VM snapshot status transitions.
 *
 * Reuses the private channel authorization (`users.{id}` and `admin.workloads`)
 * configured in `routes/channels.php` for virtual machine updates.
 */
class SnapshotStatusUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public VmSnapshot $snapshot;

    public string $status;

    public int $userId;

    public int $virtualMachineId;

    public bool $isAdmin;

    public function __construct(VmSnapshot $snapshot, ?string $status = null, bool $isAdmin = false)
    {
        $this->snapshot = $snapshot;
        $this->status = $status ?? $snapshot->status->getValue();
        $this->userId = (int) $snapshot->user_id;
        $this->virtualMachineId = (int) $snapshot->virtual_machine_id;
        $this->isAdmin = $isAdmin;
    }

    /**
     * Channels where the event should broadcast.
     *
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        if ($this->isAdmin) {
            return [
                new PrivateChannel('admin.workloads'),
            ];
        }

        return [
            new PrivateChannel('users.'.$this->userId),
            new PrivateChannel('admin.workloads'),
        ];
    }

    /**
     * Broadcast event alias for frontend listener.
     */
    public function broadcastAs(): string
    {
        return 'SnapshotStatusUpdated';
    }

    /**
     * Data payload broadcast over WebSockets.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'snapshot' => [
                'id' => $this->snapshot->id,
                'virtual_machine_id' => $this->snapshot->virtual_machine_id,
                'user_id' => $this->snapshot->user_id,
                'name' => $this->snapshot->name,
                'source' => $this->snapshot->source,
                'status' => $this->status,
                'size_bytes' => $this->snapshot->size_bytes,
                'error' => $this->snapshot->error,
                'created_at' => $this->snapshot->created_at?->toISOString(),
                'updated_at' => $this->snapshot->updated_at?->toISOString() ?? now()->toISOString(),
            ],
            'virtual_machine_id' => $this->virtualMachineId,
            'user_id' => $this->userId,
            'status' => $this->status,
        ];
    }
}
