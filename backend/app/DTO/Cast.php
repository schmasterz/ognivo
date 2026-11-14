<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Type-safe scalar and array extraction helpers compliant with PHPStan Level 10 and Psalm Level 1.
 */
final class Cast
{
    public static function string(mixed $value, string $default = ''): string
    {
        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : $default;
    }

    public static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function int(mixed $value, int $default = 0): int
    {
        return is_int($value) || (is_string($value) && is_numeric($value)) ? (int) $value : $default;
    }

    public static function intOrNull(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && is_numeric($value)) ? (int) $value : null;
    }

    public static function float(mixed $value, float $default = 0.0): float
    {
        return is_float($value) || is_int($value) || (is_string($value) && is_numeric($value)) ? (float) $value : $default;
    }

    public static function floatOrNull(mixed $value): ?float
    {
        return is_float($value) || is_int($value) || (is_string($value) && is_numeric($value)) ? (float) $value : null;
    }

    public static function bool(mixed $value, bool $default = false): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return $default;
    }

    public static function boolOrNull(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        return self::bool($value);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function arrayOrNull(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return $value;
    }
}
