<?php

declare(strict_types=1);

namespace App\ValueObjects\Vps;

/**
 * Result of evaluating whether a host can accommodate a proposed VM resource change.
 */
final readonly class CapacityCheckResult
{
    public function __construct(
        public bool $allowed,
        public ?string $reasonCode = null,
        public ?string $message = null,
    ) {}
}
