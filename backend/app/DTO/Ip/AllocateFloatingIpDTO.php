<?php

declare(strict_types=1);

namespace App\DTO\Ip;

use App\DTO\Cast;

/**
 * Immutable Data Transfer Object for requesting a floating IP address allocation.
 */
final readonly class AllocateFloatingIpDTO
{
    public function __construct(
        public ?int $poolId = null,
        public ?string $notes = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            poolId: Cast::intOrNull($data['pool_id'] ?? null),
            notes: Cast::stringOrNull($data['notes'] ?? null),
        );
    }
}
