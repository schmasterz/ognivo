<?php

declare(strict_types=1);

namespace App\DTO\Ip;

use App\DTO\Cast;

/**
 * Immutable Data Transfer Object for reassigning or detaching an IP address.
 */
final readonly class ReassignIpDTO
{
    public function __construct(
        public ?int $virtualMachineId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            virtualMachineId: Cast::intOrNull($data['virtual_machine_id'] ?? null),
        );
    }
}
