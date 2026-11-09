<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\VirtualMachine;

/**
 * Contract for virtual machine snapshot and backup storage targets.
 */
interface BackupTargetInterface
{
    /**
     * Create a snapshot/backup for the specified virtual machine.
     *
     * @param  bool  $stateful  Whether to capture running process/memory state.
     * @return bool True if creation succeeded.
     */
    public function create(VirtualMachine $vm, string $name, bool $stateful = false): bool;

    /**
     * Restore the specified virtual machine from a named snapshot/backup.
     *
     * @return bool True if restoration succeeded.
     */
    public function restore(VirtualMachine $vm, string $name): bool;

    /**
     * Delete a named snapshot/backup from the specified virtual machine.
     *
     * @return bool True if deletion succeeded.
     */
    public function delete(VirtualMachine $vm, string $name): bool;

    /**
     * List all snapshots/backups for the specified virtual machine.
     *
     * @return list<array{name: string, created_at: string, stateful: bool, size: int|null}>
     */
    public function list(VirtualMachine $vm): array;
}
