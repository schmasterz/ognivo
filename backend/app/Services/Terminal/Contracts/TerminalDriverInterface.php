<?php

declare(strict_types=1);

namespace App\Services\Terminal\Contracts;

use App\Models\VirtualMachine;

/**
 * Contract for interactive terminal session drivers (ExecDriver and ConsoleDriver).
 */
interface TerminalDriverInterface
{
    /**
     * Get the unique identifier for this terminal driver ('exec' or 'console').
     */
    public function getName(): string;

    /**
     * Open an interactive terminal operation for the specified instance.
     *
     * @return array{operation_id: string, websocket_path: string, control_path: string|null, type: string, driver: string}
     */
    public function open(VirtualMachine $vm, int $width = 80, int $height = 24, string $type = 'console'): array;
}
