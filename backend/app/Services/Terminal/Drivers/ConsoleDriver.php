<?php

declare(strict_types=1);

namespace App\Services\Terminal\Drivers;

use App\Models\VirtualMachine;
use App\Services\Hypervisor\HypervisorManager;
use App\Services\Terminal\Contracts\TerminalDriverInterface;
use Override;

/**
 * Terminal driver that attaches to the Incus /console endpoint (serial /dev/console or VGA).
 */
final readonly class ConsoleDriver implements TerminalDriverInterface
{
    public function __construct(
        private HypervisorManager $hypervisorManager,
    ) {}

    #[Override]
    public function getName(): string
    {
        return 'console';
    }

    #[Override]
    public function open(VirtualMachine $vm, int $width = 80, int $height = 24, string $type = 'console'): array
    {
        $hypervisor = $this->hypervisorManager->driverForVm($vm);
        $session = $hypervisor->createConsoleSession($vm, $type, $width, $height);

        return [
            'operation_id' => $session['operation_id'],
            'websocket_path' => $session['websocket_path'],
            'control_path' => $session['control_path'],
            'type' => $session['type'],
            'driver' => 'console',
        ];
    }
}
