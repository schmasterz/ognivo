<?php

declare(strict_types=1);

namespace App\Services\Terminal\Drivers;

use App\Models\VirtualMachine;
use App\Services\Hypervisor\HypervisorManager;
use App\Services\Terminal\Contracts\TerminalDriverInterface;
use InvalidArgumentException;
use Override;

/**
 * Terminal driver that opens an interactive shell via the Incus /exec endpoint.
 */
final readonly class ExecDriver implements TerminalDriverInterface
{
    public function __construct(
        private HypervisorManager $hypervisorManager,
    ) {}

    #[Override]
    public function getName(): string
    {
        return 'exec';
    }

    #[Override]
    public function open(VirtualMachine $vm, int $width = 80, int $height = 24, string $type = 'console'): array
    {
        if ($type === 'vga') {
            throw new InvalidArgumentException('Exec terminal driver does not support graphical VGA display.');
        }

        $hypervisor = $this->hypervisorManager->driverForVm($vm);
        $session = $hypervisor->createExecSession($vm, $width, $height);

        return [
            'operation_id' => $session['operation_id'],
            'websocket_path' => $session['websocket_path'],
            'control_path' => $session['control_path'],
            'type' => $session['type'],
            'driver' => 'exec',
        ];
    }
}
