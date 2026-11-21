<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Contracts\BackupTargetInterface;
use App\Models\VirtualMachine;
use App\Services\Hypervisor\Contracts\HypervisorDriverInterface;
use App\Services\Hypervisor\HypervisorManager;
use Override;

/**
 * BackupTargetInterface implementation that delegates snapshot operations to the local hypervisor driver.
 */
final class LocalSnapshotTarget implements BackupTargetInterface
{
    public function __construct(
        private readonly HypervisorManager|HypervisorDriverInterface|null $driverResolver = null,
    ) {}

    #[Override]
    public function create(VirtualMachine $vm, string $name): bool
    {
        return $this->driverFor($vm)->createSnapshot($vm, $name);
    }

    #[Override]
    public function restore(VirtualMachine $vm, string $name): bool
    {
        return $this->driverFor($vm)->restoreSnapshot($vm, $name);
    }

    #[Override]
    public function delete(VirtualMachine $vm, string $name): bool
    {
        return $this->driverFor($vm)->deleteSnapshot($vm, $name);
    }

    /**
     * @return list<array{name: string, created_at: string, size: int|null}>
     */
    #[Override]
    public function list(VirtualMachine $vm): array
    {
        return $this->driverFor($vm)->listSnapshots($vm);
    }

    /**
     * Alias for create() matching HypervisorDriverInterface naming.
     */
    public function createSnapshot(VirtualMachine $vm, string $name): bool
    {
        return $this->create($vm, $name);
    }

    /**
     * Alias for restore() matching HypervisorDriverInterface naming.
     */
    public function restoreSnapshot(VirtualMachine $vm, string $name): bool
    {
        return $this->restore($vm, $name);
    }

    /**
     * Alias for delete() matching HypervisorDriverInterface naming.
     */
    public function deleteSnapshot(VirtualMachine $vm, string $name): bool
    {
        return $this->delete($vm, $name);
    }

    /**
     * Alias for list() matching HypervisorDriverInterface naming.
     *
     * @return list<array{name: string, created_at: string, size: int|null}>
     */
    public function listSnapshots(VirtualMachine $vm): array
    {
        return $this->list($vm);
    }

    /**
     * Resolve the underlying hypervisor driver for the given virtual machine.
     */
    private function driverFor(VirtualMachine $vm): HypervisorDriverInterface
    {
        if ($this->driverResolver instanceof HypervisorDriverInterface) {
            return $this->driverResolver;
        }

        $manager = $this->driverResolver ?? app(HypervisorManager::class);

        return $manager->driverForVm($vm);
    }
}
