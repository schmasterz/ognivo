<?php

declare(strict_types=1);

namespace App\DTO\Hypervisor;

/**
 * Current execution status of an asynchronous hypervisor operation.
 */
final readonly class OperationStatus
{
    public function __construct(
        public OperationState $state,
        public ?string $errorCode = null,
        public ?string $safeMessage = null,
    ) {}
}
