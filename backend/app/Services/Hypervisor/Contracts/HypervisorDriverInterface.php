<?php

declare(strict_types=1);

namespace App\Services\Hypervisor\Contracts;

use App\DTO\Host\HostResourcesDTO;
use App\DTO\Hypervisor\InstanceResourceSpec;
use App\DTO\Hypervisor\OperationStatus;
use App\DTO\Hypervisor\ResourceUpdateAssessment;
use App\DTO\Hypervisor\ResourceUpdateResult;
use App\DTO\Incus\RawInstanceMetricsDTO;
use App\Exceptions\DriverUnavailableException;
use App\Models\Host;
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
     * @return bool True if snapshot creation succeeded.
     */
    public function createSnapshot(VirtualMachine $vm, string $name): bool;

    /**
     * List all snapshots for the specified instance.
     *
     * @return list<array{name: string, created_at: string, size: int|null}>
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
     * @return array{operation_id: string, websocket_path: string, control_path: string|null, type: string, driver?: string}
     */
    public function createConsoleSession(VirtualMachine $vm, string $type = 'console', int $width = 80, int $height = 24): array;

    /**
     * Create an interactive exec shell session operation on the hypervisor.
     *
     * @return array{operation_id: string, websocket_path: string, control_path: string|null, type: string, driver: string}
     */
    public function createExecSession(VirtualMachine $vm, int $width = 80, int $height = 24): array;

    /**
     * Cancel/delete a running or waiting operation on the hypervisor.
     */
    public function cancelOperation(VirtualMachine $vm, string $operationId): bool;

    /**
     * Retrieve discovered compute and storage resources for the host.
     */
    public function getHostResources(Host $host): HostResourcesDTO;

    /**
     * Retrieve raw OpenMetrics telemetry for all running instances on the specified host.
     *
     * @return array<string, RawInstanceMetricsDTO> Keyed by "{project}/{name}"
     */
    public function getHostInstanceMetrics(Host $host, int $timeoutSeconds = 10): array;

    /**
     * Retrieve the current observed resource specification of an instance from the hypervisor.
     *
     * @throws DriverUnavailableException When the hypervisor host is unreachable or fails to respond.
     */
    public function getInstanceResourceSpec(VirtualMachine $vm): InstanceResourceSpec;

    /**
     * Assess whether a target resource specification can be applied online, requires stop, or is unsupported.
     */
    public function assessResourceUpdate(VirtualMachine $vm, InstanceResourceSpec $target): ResourceUpdateAssessment;

    /**
     * Apply a target resource specification update to the instance on the hypervisor.
     */
    public function applyResourceUpdate(VirtualMachine $vm, InstanceResourceSpec $target): ResourceUpdateResult;

    /**
     * Query the status of an asynchronous hypervisor operation.
     */
    public function getOperationStatus(VirtualMachine $vm, string $operationId): OperationStatus;
}
