<?php

declare(strict_types=1);

namespace App\DTO\Incus;

use App\States\Host\Error;
use App\States\Host\FingerprintMismatch;
use App\States\Host\HostState;
use App\States\Host\NotTrusted;
use App\States\Host\Ok;
use App\States\Host\Unreachable;

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
     * Resolve the HostState class corresponding to this connection check result.
     *
     * @return class-string<HostState>
     */
    public function hostStateClass(): string
    {
        return match ($this->state) {
            self::STATE_OK => Ok::class,
            self::STATE_UNREACHABLE => Unreachable::class,
            self::STATE_FINGERPRINT_MISMATCH => FingerprintMismatch::class,
            self::STATE_NOT_TRUSTED => NotTrusted::class,
            default => Error::class,
        };
    }

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
