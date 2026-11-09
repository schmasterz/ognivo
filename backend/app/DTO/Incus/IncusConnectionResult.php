<?php

declare(strict_types=1);

namespace App\DTO\Incus;

/**
 * Structured result of an Incus connection and trust verification check.
 */
final readonly class IncusConnectionResult
{
    public const STATE_OK = 'ok';

    public const STATE_UNREACHABLE = 'unreachable';

    public const STATE_FINGERPRINT_MISMATCH = 'fingerprint_mismatch';

    public const STATE_NOT_TRUSTED = 'not_trusted';

    public const STATE_ERROR = 'error';

    /**
     * @param  array<string, mixed>|null  $details
     */
    public function __construct(
        public string $state,
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
            'state' => $this->state,
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
