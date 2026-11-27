<?php

declare(strict_types=1);

namespace App\ValueObjects\Hypervisor;

use App\Enums\Hypervisor\OperationState;

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
