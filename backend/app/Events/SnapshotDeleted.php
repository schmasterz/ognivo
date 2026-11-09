<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\VirtualMachine;
use App\Models\VmSnapshot;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Domain event dispatched when a virtual machine snapshot has been deleted from the backup target.
 */
final class SnapshotDeleted
{
    use Dispatchable;
    use InteractsWithSockets;

    public readonly ?VirtualMachine $vm;

    public readonly ?VirtualMachine $virtualMachine;

    public readonly int $userId;

    public function __construct(
        public readonly VmSnapshot $snapshot,
        ?VirtualMachine $vm = null,
    ) {
        $resolvedVm = $vm ?? ($snapshot->relationLoaded('virtualMachine') ? $snapshot->virtualMachine : VirtualMachine::find($snapshot->virtual_machine_id));
        $this->vm = $resolvedVm;
        $this->virtualMachine = $resolvedVm;
        $this->userId = (int) $snapshot->user_id;
    }
}
