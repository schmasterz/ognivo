<?php

declare(strict_types=1);

namespace App\DTO\Vm;

/**
 * Immutable DTO representing a safe interactive console session response for clients.
 * Guarantees zero leakage of hypervisor host URLs, pins, or operation secrets.
 */
final readonly class ConsoleSessionDTO
{
    public function __construct(
        public int $virtualMachineId,
        public string $ticket,
        public string $websocketPath,
        public string $expiresAt,
        public string $type = 'console',
    ) {}

    /**
     * @return array{virtual_machine_id: int, ticket: string, websocket_path: string, expires_at: string, type: string}
     */
    public function toArray(): array
    {
        return [
            'virtual_machine_id' => $this->virtualMachineId,
            'ticket' => $this->ticket,
            'websocket_path' => $this->websocketPath,
            'expires_at' => $this->expiresAt,
            'type' => $this->type,
        ];
    }
}
