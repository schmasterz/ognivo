<?php

declare(strict_types=1);

namespace App\Audit;

use App\Support\Cast;
use RuntimeException;

final class AuditConfigValidator
{
    /**
     * Unsafe example or placeholder values that must not be used in production.
     *
     * @var array<int, string>
     */
    public const EXAMPLE_VALUES = [
        '',
        'CHANGE_ME',
        'example',
        'example_key',
        'example_hmac_key',
        'example_salt',
        'example_pseudonym_salt',
    ];

    /**
     * Check if the audit configuration is valid for running audit commands in production.
     * Throws a RuntimeException if production configuration is unsafe.
     *
     * @throws RuntimeException
     */
    public static function validateProductionAuditCommand(?string $commandName = null): void
    {
        if (! (bool) app()->environment('production')) {
            return;
        }

        $hmacKey = Cast::string(config('audit.hmac.current_key', ''));
        $pseudonymSalt = Cast::string(config('audit.pseudonym_salt', ''));

        $violations = [];

        if (self::isUnsafeValue($hmacKey)) {
            $valDisplay = $hmacKey === '' ? '(empty)' : "('{$hmacKey}')";
            $violations[] = "AUDIT_HMAC_KEY is empty or equals the example value {$valDisplay}.";
        }

        if (self::isUnsafeValue($pseudonymSalt)) {
            $valDisplay = $pseudonymSalt === '' ? '(empty)' : "('{$pseudonymSalt}')";
            $violations[] = "AUDIT_PSEUDONYM_SALT is empty or equals the example value {$valDisplay}.";
        }

        if (! empty($violations)) {
            $cmdText = $commandName !== null ? "audit command '{$commandName}'" : 'audit commands';
            throw new RuntimeException("Refusing to run {$cmdText} in production: ".implode(' ', $violations));
        }
    }

    /**
     * Determine if a given value is considered empty or an unsafe example placeholder.
     */
    public static function isUnsafeValue(?string $value): bool
    {
        if ($value === null) {
            return true;
        }

        $trimmed = trim($value);

        return in_array($trimmed, self::EXAMPLE_VALUES, true);
    }
}
