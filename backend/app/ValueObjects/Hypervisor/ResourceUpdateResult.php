<?php

declare(strict_types=1);

namespace App\ValueObjects\Hypervisor;

use App\Enums\Hypervisor\ResourceUpdateStatus;

/**
 * Structured result returned by a hypervisor driver when applying a resource update.
 */
final readonly class ResourceUpdateResult
{
    public function __construct(
        public ResourceUpdateStatus $status,
        public ?string $operationId = null,
        public ?string $errorCode = null,
        public ?string $safeMessage = null,
    ) {}
}
