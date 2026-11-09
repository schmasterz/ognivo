<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Audit\AuditConfigValidator;
use RuntimeException;
use Tests\TestCase;

final class AuditConfigValidatorTest extends TestCase
{
    private function setEnv(string $key, string $value): void
    {
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv("{$key}={$value}");
    }

    public function test_allows_valid_keys_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        config([
            'audit.hmac.current_key' => bin2hex(random_bytes(32)),
            'audit.pseudonym_salt' => bin2hex(random_bytes(32)),
        ]);

        // Should not throw
        AuditConfigValidator::validateProductionAuditCommand('audit:seal');
        $this->assertTrue(true);
    }

    public function test_rejects_empty_hmac_key_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        config([
            'audit.hmac.current_key' => '',
            'audit.pseudonym_salt' => bin2hex(random_bytes(32)),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AUDIT_HMAC_KEY is empty or equals the example value');

        AuditConfigValidator::validateProductionAuditCommand('audit:seal');
    }

    public function test_rejects_placeholder_hmac_key_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        config([
            'audit.hmac.current_key' => 'CHANGE_ME',
            'audit.pseudonym_salt' => bin2hex(random_bytes(32)),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("AUDIT_HMAC_KEY is empty or equals the example value ('CHANGE_ME')");

        AuditConfigValidator::validateProductionAuditCommand('audit:seal');
    }

    public function test_rejects_empty_pseudonym_salt_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        config([
            'audit.hmac.current_key' => bin2hex(random_bytes(32)),
            'audit.pseudonym_salt' => '',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AUDIT_PSEUDONYM_SALT is empty or equals the example value');

        AuditConfigValidator::validateProductionAuditCommand('audit:pseudonymise');
    }

    public function test_rejects_placeholder_pseudonym_salt_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        config([
            'audit.hmac.current_key' => bin2hex(random_bytes(32)),
            'audit.pseudonym_salt' => 'CHANGE_ME',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("AUDIT_PSEUDONYM_SALT is empty or equals the example value ('CHANGE_ME')");

        AuditConfigValidator::validateProductionAuditCommand('audit:pseudonymise');
    }

    public function test_allows_placeholders_outside_production(): void
    {
        $this->app->detectEnvironment(fn () => 'testing');

        config([
            'audit.hmac.current_key' => 'CHANGE_ME',
            'audit.pseudonym_salt' => 'CHANGE_ME',
        ]);

        // Should not throw in testing/development
        AuditConfigValidator::validateProductionAuditCommand('audit:seal');
        $this->assertTrue(true);
    }
}
