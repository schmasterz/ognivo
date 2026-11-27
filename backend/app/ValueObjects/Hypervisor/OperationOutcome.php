<?php

declare(strict_types=1);

namespace App\ValueObjects\Hypervisor;

/**
 * Structured outcome of a hypervisor synchronous or asynchronous operation.
 */
final readonly class OperationOutcome
{
    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_RUNNING = 'running';

    public const STATUS_FAILED = 'failed';

    /**
     * @param  self::STATUS_*  $status
     */
    public function __construct(
        public string $status,
        public ?string $operationId = null,
        public ?string $errorMessage = null,
    ) {}

    public static function succeeded(?string $operationId = null): self
    {
        return new self(
            status: self::STATUS_SUCCEEDED,
            operationId: $operationId,
        );
    }

    public static function running(?string $operationId = null): self
    {
        return new self(
            status: self::STATUS_RUNNING,
            operationId: $operationId,
        );
    }

    public static function failed(?string $errorMessage = null, ?string $operationId = null): self
    {
        return new self(
            status: self::STATUS_FAILED,
            operationId: $operationId,
            errorMessage: $errorMessage,
        );
    }

    public function isSucceeded(): bool
    {
        return $this->status === self::STATUS_SUCCEEDED;
    }

    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }
}
