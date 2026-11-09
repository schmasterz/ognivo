<?php

declare(strict_types=1);

namespace App\DTO\System;

use App\DTO\Cast;

/**
 * Immutable Data Transfer Object for updating system platform configuration.
 */
final readonly class UpdateSettingsDTO
{
    /**
     * @param array{
     *     enabled?: bool,
     *     host?: string|null,
     *     port?: int|null,
     *     encryption?: string|null,
     *     username?: string|null,
     *     password?: string|null,
     *     from_address?: string|null,
     *     from_name?: string|null
     * }|null $smtp
     */
    public function __construct(
        public ?bool $registrationEnabled = null,
        public ?bool $allowVirtualMachines = null,
        public ?bool $allowContainers = null,
        public ?array $smtp = null,
        public ?string $frontendUrl = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array{
         *     enabled?: bool,
         *     host?: string|null,
         *     port?: int|null,
         *     encryption?: string|null,
         *     username?: string|null,
         *     password?: string|null,
         *     from_address?: string|null,
         *     from_name?: string|null
         * }|null $smtp
         */
        $smtp = isset($data['smtp']) && is_array($data['smtp']) ? $data['smtp'] : null;

        return new self(
            registrationEnabled: isset($data['registration_enabled']) ? Cast::bool($data['registration_enabled']) : null,
            allowVirtualMachines: isset($data['allow_virtual_machines']) ? Cast::bool($data['allow_virtual_machines']) : null,
            allowContainers: isset($data['allow_containers']) ? Cast::bool($data['allow_containers']) : null,
            smtp: $smtp,
            frontendUrl: isset($data['frontend_url']) ? Cast::string($data['frontend_url']) : null,
        );
    }
}
