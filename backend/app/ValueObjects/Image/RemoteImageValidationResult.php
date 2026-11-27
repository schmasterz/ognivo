<?php

declare(strict_types=1);

namespace App\ValueObjects\Image;

/**
 * Immutable result of validating an OS image entry against a remote image server.
 */
final readonly class RemoteImageValidationResult
{
    /**
     * @param  list<string>  $architectures
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public bool $isValid,
        public ?string $error = null,
        public array $architectures = [],
        public ?string $matchedAlias = null,
        public array $metadata = [],
    ) {}

    /**
     * @param  list<string>  $architectures
     * @param  array<string, mixed>  $metadata
     */
    public static function success(
        string $matchedAlias,
        array $architectures = [],
        array $metadata = [],
    ): self {
        return new self(
            isValid: true,
            error: null,
            architectures: $architectures,
            matchedAlias: $matchedAlias,
            metadata: $metadata,
        );
    }

    public static function failure(string $error): self
    {
        return new self(
            isValid: false,
            error: $error,
            architectures: [],
            matchedAlias: null,
            metadata: [],
        );
    }

    /**
     * @return array{valid: bool, error: ?string, architectures: list<string>, matched_alias: ?string, metadata: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'valid' => $this->isValid,
            'error' => $this->error,
            'architectures' => $this->architectures,
            'matched_alias' => $this->matchedAlias,
            'metadata' => $this->metadata,
        ];
    }
}
