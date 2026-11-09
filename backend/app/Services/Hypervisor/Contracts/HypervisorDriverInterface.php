<?php

declare(strict_types=1);

namespace App\Services\Hypervisor\Contracts;

use App\Models\VirtualMachine;

/**
 * Interface contract for hypervisor providers (Incus, Fake/Mock, Proxmox, etc.).
 */
interface HypervisorDriverInterface
{
    /**
     * Create an instance (VM or container) on the hypervisor host.
     *
     * @return bool True if creation was initiated or completed successfully.
     */
    public function createInstance(VirtualMachine $vm): bool;

    /**
     * Start the specified instance.
     *
     * @return bool True if startup succeeded.
     */
    public function startInstance(VirtualMachine $vm): bool;

    /**
     * Stop or shutdown the specified instance.
     *
     * @param  bool  $force  Whether to force immediate termination (SIGKILL).
     * @return bool True if shutdown succeeded.
     */
    public function stopInstance(VirtualMachine $vm, bool $force = false): bool;

    /**
     * Suspend (pause or freeze) the specified instance.
     *
     * @return bool True if suspend succeeded.
     */
    public function suspendInstance(VirtualMachine $vm): bool;

    /**
     * Delete the specified instance and release its storage.
     *
     * @return bool True if deletion succeeded.
     */
    public function deleteInstance(VirtualMachine $vm): bool;

    /**
     * Retrieve the current live status from the hypervisor.
     *
     * @return string Status string (e.g. 'Running', 'Stopped', 'Error').
     */
    public function getInstanceStatus(VirtualMachine $vm): string;

    /**
     * Retrieve full live instance state including network device addresses.
     *
     * @return array<string, mixed>
     */
    public function getInstanceState(VirtualMachine $vm): array;

    /**
     * Retrieve the raw boot/console log buffer from the hypervisor.
     */
    public function getInstanceConsoleLog(VirtualMachine $vm): string;

    /**
     * List projects on the hypervisor host.
     *
     * @return list<string>
     */
    public function listProjects(): array;

    /**
     * List all instances on the hypervisor for a given project (or all projects).
     *
     * @return list<array{name: string, project: string, status: string}>
     */
    public function listInstances(?string $project = null): array;

    /**
     * Delete the specified project on the hypervisor host.
     *
     * @return bool True if deletion succeeded or project does not exist.
     */
    public function deleteProject(string $project): bool;

    /**
     * Create a snapshot for the specified instance.
     *
     * @param  bool  $stateful  Whether to capture running process/memory state.
     * @return bool True if snapshot creation succeeded.
     */
    public function createSnapshot(VirtualMachine $vm, string $name, bool $stateful = false): bool;

    /**
     * List all snapshots for the specified instance.
     *
     * @return list<array{name: string, created_at: string, stateful: bool, size: int|null}>
     */
    public function listSnapshots(VirtualMachine $vm): array;

    /**
     * Restore the specified instance to a named snapshot.
     *
     * @return bool True if snapshot restoration succeeded.
     */
    public function restoreSnapshot(VirtualMachine $vm, string $name): bool;

    /**
     * Delete a named snapshot from the specified instance.
     *
     * @return bool True if snapshot deletion succeeded.
     */
    public function deleteSnapshot(VirtualMachine $vm, string $name): bool;

    /**
     * Create an interactive console session operation on the hypervisor.
     *
     * @param  string  $type  Console type ('console' for text/serial, 'vga' for graphical display).
     * @return array{operation_id: string, websocket_path: string, control_path: string|null, type: string}
     */
    public function createConsoleSession(VirtualMachine $vm, string $type = 'console'): array;
}
