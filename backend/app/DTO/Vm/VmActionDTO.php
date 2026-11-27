<?php

declare(strict_types=1);

namespace App\DTO\Vm;

use App\Support\Cast;

/**
 * Immutable Data Transfer Object for virtual machine lifecycle actions.
 */
final readonly class VmActionDTO
{
    /**
     * @param  array<string, mixed>|null  $cloudInit
     */
    public function __construct(
        public string $action,
        public bool $force = false,
        public ?string $osImage = null,
        public ?array $cloudInit = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            action: Cast::string($data['action'] ?? ''),
            force: Cast::bool($data['force'] ?? false),
            osImage: Cast::stringOrNull($data['os_image'] ?? null),
            cloudInit: Cast::arrayOrNull($data['cloud_init'] ?? null),
        );
    }
}
