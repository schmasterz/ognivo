<?php

declare(strict_types=1);

namespace App\ValueObjects\Hypervisor;

use App\Enums\Hypervisor\ConnectionCheckState;

/**
 * Structured result of an Incus connection and trust verification check.
 */
final readonly class IncusConnectionResult
{
    /**
     * @param  array<string, mixed>|null  $details
     */
    public function __construct(
        public ConnectionCheckState $state,
        public bool $success,
        public string $message,
        public ?string $server = null,
        public ?string $serverVersion = null,
        public ?int $latencyMs = null,
        public ?bool $isTrusted = null,
        public ?string $auth = null,
        public ?array $details = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'success' => $this->success,
            'message' => $this->message,
            'server' => $this->server,
            'server_version' => $this->serverVersion,
            'latency_ms' => $this->latencyMs,
            'is_trusted' => $this->isTrusted,
            'auth' => $this->auth,
            'details' => $this->details ?? [],
        ];
    }
}
