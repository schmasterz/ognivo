<?php

declare(strict_types=1);

namespace App\DTO\Vm;

use App\DTO\Cast;

/**
 * Immutable Data Transfer Object for virtual machine lifecycle actions.
 */
final readonly class VmActionDTO
{
    public function __construct(
        public string $action,
        public bool $force = false,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            action: Cast::string($data['action'] ?? ''),
            force: Cast::bool($data['force'] ?? false),
        );
    }
}
