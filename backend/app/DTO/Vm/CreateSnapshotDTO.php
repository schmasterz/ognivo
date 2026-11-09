<?php

declare(strict_types=1);

namespace App\DTO\Vm;

use App\DTO\Cast;

/**
 * Immutable Data Transfer Object for creating a virtual machine snapshot.
 */
final readonly class CreateSnapshotDTO
{
    public function __construct(
        public string $name,
        public bool $stateful = false,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: Cast::string($data['name'] ?? ''),
            stateful: Cast::bool($data['stateful'] ?? false),
        );
    }

    /**
     * @return array{name: string, stateful: bool}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'stateful' => $this->stateful,
        ];
    }
}
