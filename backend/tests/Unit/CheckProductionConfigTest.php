<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\CheckProductionConfigCommand;
use Tests\TestCase;

final class CheckProductionConfigTest extends TestCase
{
    /**
     * @var array<string, string|false>
     */
    private array $originalGetenv = [];

    /**
     * @var array<string, mixed>
     */
    private array $originalEnv = [];

    /**
     * @var array<string, mixed>
     */
    private array $originalServer = [];

    /**
     * @var array<string, mixed>
     */
    private array $originalConfig = [];

    private const TRACKED_ENV_VARS = [
        'APP_KEY',
        'APP_DEBUG',
        'APP_URL',
        'ALLOW_FAKE_DRIVER',
        'RUN_SEEDERS',
        'DB_PASSWORD',
        'DB_ROOT_PASSWORD',
        'REVERB_APP_SECRET',
        'REVERB_ALLOWED_ORIGINS',
        'REDIS_PASSWORD',
        'REVERB_APP_KEY',
        'AUDIT_DB_PASSWORD',
        'AUDIT_MAINT_DB_PASSWORD',
        'DB_MIGRATION_PASSWORD',
        'AUDIT_HMAC_KEY',
        'AUDIT_HMAC_KEY_ID',
        'AUDIT_PSEUDONYM_SALT',
        'AUDIT_STRICT_GRANTS',
        'SESSION_SECURE_COOKIE',
        'SESSION_SAME_SITE',
        'SESSION_DOMAIN',
        'SESSION_COOKIE',
        'CONSOLE_ALLOWED_ORIGINS',
    ];

    private const TRACKED_CONFIGS = [
        'app.key',
        'app.debug',
        'app.url',
        'app.allow_fake_driver',
        'app.run_seeders',
        'database.connections.mysql.password',
        'database.connections.mysql.root_password',
        'reverb.apps.apps.0.secret',
        'reverb.apps.apps.0.allowed_origins',
        'database.redis.default.password',
        'reverb.apps.apps.0.key',
        'database.connections.audit.password',
        'database.connections.audit_maintenance.password',
        'database.connections.migration.password',
        'audit.hmac_keys.v1',
        'audit.hmac_key_id',
        'audit.hmac.current_key',
        'audit.hmac.keys.v1',
        'audit.pseudonym_salt',
        'audit.strict_grants',
        'session.secure',
        'session.same_site',
        'session.domain',
        'session.cookie',
        'sanctum.stateful',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalGetenv = [];
        $this->originalEnv = [];
        $this->originalServer = [];
        $this->originalConfig = [];

        foreach (self::TRACKED_ENV_VARS as $key) {
            $this->originalGetenv[$key] = getenv($key);
            if (array_key_exists($key, $_ENV)) {
                $this->originalEnv[$key] = $_ENV[$key];
            }
            if (array_key_exists($key, $_SERVER)) {
                $this->originalServer[$key] = $_SERVER[$key];
            }
        }

        foreach (self::TRACKED_CONFIGS as $key) {
            $this->originalConfig[$key] = config($key);
        }
    }

    protected function tearDown(): void
    {
        foreach (self::TRACKED_ENV_VARS as $key) {
            if ($this->originalGetenv[$key] === false) {
                putenv($key);
            } else {
                putenv("{$key}={$this->originalGetenv[$key]}");
            }

            if (array_key_exists($key, $this->originalEnv)) {
                $_ENV[$key] = $this->originalEnv[$key];
            } else {
                unset($_ENV[$key]);
            }

            if (array_key_exists($key, $this->originalServer)) {
                $_SERVER[$key] = $this->originalServer[$key];
            } else {
                unset($_SERVER[$key]);
            }
        }

        foreach ($this->originalConfig as $key => $val) {
            config([$key => $val]);
        }

        parent::tearDown();
    }

    private function setEnv(string $key, string $value): void
    {
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv("{$key}={$value}");
    }

    /**
     * Set up a safe, valid baseline production configuration.
     */
    private function setValidProductionConfig(): void
    {
        $validKey = 'base64:'.base64_encode(random_bytes(32));

        config([
            'app.key' => $validKey,
            'app.debug' => false,
            'app.url' => 'https://panel.example.com',
            'app.allow_fake_driver' => false,
            'app.run_seeders' => false,
            'database.connections.mysql.password' => 'super_secure_random_db_pass_1234',
            'database.connections.mysql.root_password' => 'super_secure_random_root_pass_5678',
            'reverb.apps.apps.0.secret' => 'super_secure_reverb_secret_9999',
            'reverb.apps.apps.0.allowed_origins' => ['https://panel.example.com'],
            'database.redis.default.password' => 'super_secure_redis_password_9876',
            'reverb.apps.apps.0.key' => 'valid_reverb_app_key_1234',
            'database.connections.audit.password' => 'super_secure_audit_pass_1234',
            'database.connections.audit_maintenance.password' => 'super_secure_audit_maint_pass_5678',
            'database.connections.migration.password' => 'super_secure_migration_pass_9012',
            'audit.hmac_keys.v1' => 'super_secure_audit_hmac_key_3456',
            'audit.hmac_key_id' => 'v1',
            'audit.hmac.current_key' => 'super_secure_audit_hmac_key_3456',
            'audit.hmac.keys.v1' => 'super_secure_audit_hmac_key_3456',
            'audit.pseudonym_salt' => 'super_secure_audit_salt_7890',
            'audit.strict_grants' => true,
            'session.secure' => true,
            'session.same_site' => 'strict',
            'session.domain' => null,
            'session.cookie' => '__Host-ognivo_session',
            'sanctum.stateful' => ['panel.example.com'],
        ]);

        $this->setEnv('APP_KEY', $validKey);
        $this->setEnv('APP_DEBUG', 'false');
        $this->setEnv('APP_URL', 'https://panel.example.com');
        $this->setEnv('ALLOW_FAKE_DRIVER', 'false');
        $this->setEnv('RUN_SEEDERS', 'false');
        $this->setEnv('DB_PASSWORD', 'super_secure_random_db_pass_1234');
        $this->setEnv('DB_ROOT_PASSWORD', 'super_secure_random_root_pass_5678');
        $this->setEnv('REVERB_APP_SECRET', 'super_secure_reverb_secret_9999');
        $this->setEnv('REVERB_ALLOWED_ORIGINS', 'https://panel.example.com');
        $this->setEnv('REDIS_PASSWORD', 'super_secure_redis_password_9876');
        $this->setEnv('REVERB_APP_KEY', 'valid_reverb_app_key_1234');
        $this->setEnv('AUDIT_DB_PASSWORD', 'super_secure_audit_pass_1234');
        $this->setEnv('AUDIT_MAINT_DB_PASSWORD', 'super_secure_audit_maint_pass_5678');
        $this->setEnv('DB_MIGRATION_PASSWORD', 'super_secure_migration_pass_9012');
        $this->setEnv('AUDIT_HMAC_KEY', 'super_secure_audit_hmac_key_3456');
        $this->setEnv('AUDIT_HMAC_KEY_ID', 'v1');
        $this->setEnv('AUDIT_PSEUDONYM_SALT', 'super_secure_audit_salt_7890');
        $this->setEnv('AUDIT_STRICT_GRANTS', 'true');
        $this->setEnv('SESSION_SECURE_COOKIE', 'true');
        $this->setEnv('SESSION_SAME_SITE', 'strict');
        $this->setEnv('SESSION_DOMAIN', '');
        $this->setEnv('SESSION_COOKIE', '__Host-ognivo_session');
        $this->setEnv('CONSOLE_ALLOWED_ORIGINS', 'https://panel.example.com');
    }

    public function test_valid_configuration_passes(): void
    {
        $this->setValidProductionConfig();

        $this->artisan('app:check-production-config')
            ->expectsOutputToContain('all security checks verified')
            ->assertExitCode(0);
    }

    public function test_rejects_empty_app_key(): void
    {
        $this->setValidProductionConfig();
        config(['app.key' => '']);
        $this->setEnv('APP_KEY', '');

        $this->artisan('app:check-production-config')
            ->expectsOutputToContain('APP_KEY is empty')
            ->assertExitCode(1);
    }

    public function test_rejects_previously_committed_app_key(): void
    {
        $this->setValidProductionConfig();
        config(['app.key' => CheckProductionConfigCommand::COMMITTED_APP_KEY]);
        $this->setEnv('APP_KEY', CheckProductionConfigCommand::COMMITTED_APP_KEY);

        $this->artisan('app:check-production-config')
            ->expectsOutputToContain('APP_KEY matches the publicly compromised key')
            ->assertExitCode(1);
    }

    public function test_rejects_placeholder_app_key(): void
    {
        $this->setValidProductionConfig();
        config(['app.key' => 'CHANGE_ME']);
        $this->setEnv('APP_KEY', 'CHANGE_ME');

        $this->artisan('app:check-production-config')
            ->expectsOutputToContain("APP_KEY is set to placeholder 'CHANGE_ME'")
            ->assertExitCode(1);
    }

    public function test_rejects_secret_password_for_db_password(): void
    {
        $this->setValidProductionConfig();
        config(['database.connections.mysql.password' => 'secret_password']);
        $this->setEnv('DB_PASSWORD', 'secret_password');

        $this->artisan('app:check-production-config')
            ->expectsOutputToContain('DB_PASSWORD is set to an unsafe default')
            ->assertExitCode(1);
    }

    public function test_rejects_change_me_for_db_password(): void
    {
        $this->setValidProductionConfig();
        config(['database.connections.mysql.password' => 'CHANGE_ME']);
        $this->setEnv('DB_PASSWORD', 'CHANGE_ME');

        $this->artisan('app:check-production-config')
            ->expectsOutputToContain('DB_PASSWORD is set to an unsafe default')
            ->assertExitCode(1);
    }

    public function test_rejects_secret_password_for_db_root_password(): void
    {
        $this->setValidProductionConfig();
        config(['database.connections.mysql.root_password' => 'secret_password']);
        $this->setEnv('DB_ROOT_PASSWORD', 'secret_password');

        $this->artisan('app:check-production-config')
            ->expectsOutputToContain('DB_ROOT_PASSWORD is set to an unsafe default')
            ->assertExitCode(1);
    }

    public function test_rejects_change_me_for_db_root_password(): void
    {
        $this->setValidProductionConfig();
        config(['database.connections.mysql.root_password' => 'CHANGE_ME']);
        $this->setEnv('DB_ROOT_PASSWORD', 'CHANGE_ME');

        $this->artisan('app:check-production-config')
            ->expectsOutputToContain('DB_ROOT_PASSWORD is set to an unsafe default')
            ->assertExitCode(1);
    }

    public function test_rejects_reverb_secret_key_default(): void
    {
        $this->setValidProductionConfig();
        config(['reverb.apps.apps.0.secret' => 'reverb_secret_key']);
        $this->setEnv('REVERB_APP_SECRET', 'reverb_secret_key');

        $this->artisan('app:check-production-config')
            ->expectsOutputToContain('REVERB_APP_SECRET is set to an unsafe default')
            ->assertExitCode(1);
    }

    public function test_rejects_reverb_secret_change_me(): void
    {
        $this->setValidProductionConfig();
        config(['reverb.apps.apps.0.secret' => 'CHANGE_ME']);
        $this->setEnv('REVERB_APP_SECRET', 'CHANGE_ME');

        $this->artisan('app:check-production-config')
            ->expectsOutputToContain('REVERB_APP_SECRET is set to an unsafe default')
            ->assertExitCode(1);
    }

    public function test_rejects_reverb_allowed_origins_with_wildcard(): void
    {
        $this->setValidProductionConfig();
        config(['reverb.apps.apps.0.allowed_origins' => ['*']]);
        $this->setEnv('REVERB_ALLOWED_ORIGINS', '*');

        $this->artisan('app:check-production-config')
            ->expectsOutputToContain("REVERB_ALLOWED_ORIGINS contains wildcard '*'")
            ->assertExitCode(1);
    }

    public function test_rejects_app_debug_true(): void
    {
        $this->setValidProductionConfig();
        config(['app.debug' => true]);
        $this->setEnv('APP_DEBUG', 'true');

        $this->artisan('app:check-production-config')
            ->expectsOutputToContain('APP_DEBUG is enabled')
            ->assertExitCode(1);
    }

    public function test_rejects_run_seeders_true(): void
    {
        $this->setValidProductionConfig();
        config(['app.run_seeders' => true]);
        $this->setEnv('RUN_SEEDERS', 'true');

        $this->artisan('app:check-production-config')
            ->expectsOutputToContain('RUN_SEEDERS is enabled')
            ->assertExitCode(1);
    }

    public function test_rejects_empty_redis_password(): void
    {
        $this->setValidProductionConfig();
        config(['database.redis.default.password' => '']);
        $this->setEnv('REDIS_PASSWORD', '');

        $this->artisan('app:check-production-config')
            ->expectsOutputToContain('REDIS_PASSWORD is empty')
            ->assertExitCode(1);
    }

    public function test_rejects_secret_password_for_redis(): void
    {
        $this->setValidProductionConfig();
        config(['database.redis.default.password' => 'secret_password']);
        $this->setEnv('REDIS_PASSWORD', 'secret_password');

        $this->artisan('app:check-production-config')
            ->expectsOutputToContain("REDIS_PASSWORD is set to an unsafe default ('secret_password')")
            ->assertExitCode(1);
    }

    public function test_rejects_change_me_for_redis(): void
    {
        $this->setValidProductionConfig();
        config(['database.redis.default.password' => 'CHANGE_ME']);
        $this->setEnv('REDIS_PASSWORD', 'CHANGE_ME');

        $this->artisan('app:check-production-config')
            ->expectsOutputToContain("REDIS_PASSWORD is set to an unsafe default ('CHANGE_ME')")
            ->assertExitCode(1);
    }

    public function test_rejects_empty_reverb_app_key(): void
    {
        $this->setValidProductionConfig();
        config(['reverb.apps.apps.0.key' => '']);
        $this->setEnv('REVERB_APP_KEY', '');

        $this->artisan('app:check-production-config')
            ->expectsOutputToContain('REVERB_APP_KEY is empty')
            ->assertExitCode(1);
    }

    public function test_rejects_change_me_for_reverb_app_key(): void
    {
        $this->setValidProductionConfig();
        config(['reverb.apps.apps.0.key' => 'CHANGE_ME']);
        $this->setEnv('REVERB_APP_KEY', 'CHANGE_ME');

        $this->artisan('app:check-production-config')
            ->expectsOutputToContain("REVERB_APP_KEY is set to an unsafe default ('CHANGE_ME')")
            ->assertExitCode(1);
    }

    public function test_rejects_empty_audit_db_password(): void
    {
        $this->setValidProductionConfig();
        config(['database.connections.audit.password' => '']);
        $this->setEnv('AUDIT_DB_PASSWORD', '');

        $this->artisan('app:check-production-config')
            ->expectsOutputToContain('AUDIT_DB_PASSWORD is empty')
            ->assertExitCode(1);
    }

    public function test_rejects_change_me_for_audit_db_password(): void
    {
        $this->setValidProductionConfig();
        config(['database.connections.audit.password' => 'CHANGE_ME']);
        $this->setEnv('AUDIT_DB_PASSWORD', 'CHANGE_ME');

        $this->artisan('app:check-production-config')
            ->expectsOutputToContain("AUDIT_DB_PASSWORD is set to an unsafe default ('CHANGE_ME')")
            ->assertExitCode(1);
    }

    public function test_rejects_secret_password_for_audit_db_password(): void
    {
        $this->setValidProductionConfig();
        config(['database.connections.audit.password' => 'secret_password']);
        $this->setEnv('AUDIT_DB_PASSWORD', 'secret_password');

        $this->artisan('app:check-production-config')
            ->expectsOutputToContain("AUDIT_DB_PASSWORD is set to an unsafe default ('secret_password')")
            ->assertExitCode(1);
    }

    public function test_rejects_empty_audit_maint_db_password(): void
    {
        $this->setValidProductionConfig();
        config(['database.connections.audit_maintenance.password' => '']);
        $this->setEnv('AUDIT_MAINT_DB_PASSWORD', '');

        $this->artisan('app:check-production-config')
            ->expectsOutputToContain('AUDIT_MAINT_DB_PASSWORD is empty')
            ->assertExitCode(1);
    }

    public function test_rejects_change_me_for_audit_maint_db_password(): void
    {
        $this->setValidProductionConfig();
        config(['database.connections.audit_maintenance.password' => 'CHANGE_ME']);
        $this->setEnv('AUDIT_MAINT_DB_PASSWORD', 'CHANGE_ME');

        $this->artisan('app:check-production-config')
            ->expectsOutputToContain("AUDIT_MAINT_DB_PASSWORD is set to an unsafe default ('CHANGE_ME')")
            ->assertExitCode(1);
    }

    public function test_rejects_secret_password_for_audit_maint_db_password(): void
    {
        $this->setValidProductionConfig();
        config(['database.connections.audit_maintenance.password' => 'secret_password']);
        $this->setEnv('AUDIT_MAINT_DB_PASSWORD', 'secret_password');

        $this->artisan('app:check-production-config')
            ->expectsOutputToContain("AUDIT_MAINT_DB_PASSWORD is set to an unsafe default ('secret_password')")
            ->assertExitCode(1);
    }

    public function test_rejects_empty_db_migration_password(): void
    {
        $this->setValidProductionConfig();
        config(['database.connections.migration.password' => '']);
        $this->setEnv('DB_MIGRATION_PASSWORD', '');

        $this->artisan('app:check-production-config')
            ->expectsOutputToContain('DB_MIGRATION_PASSWORD is empty')
            ->assertExitCode(1);
    }

    public function test_rejects_change_me_for_db_migration_password(): void
    {
        $this->setValidProductionConfig();
        config(['database.connections.migration.password' => 'CHANGE_ME']);
        $this->setEnv('DB_MIGRATION_PASSWORD', 'CHANGE_ME');

        $this->artisan('app:check-production-config')
            ->expectsOutputToContain("DB_MIGRATION_PASSWORD is set to an unsafe default ('CHANGE_ME')")
            ->assertExitCode(1);
    }

    public function test_rejects_secret_password_for_db_migration_password(): void
    {
        $this->setValidProductionConfig();
        config(['database.connections.migration.password' => 'secret_password']);
        $this->setEnv('DB_MIGRATION_PASSWORD', 'secret_password');

        $this->artisan('app:check-production-config')
            ->expectsOutputToContain("DB_MIGRATION_PASSWORD is set to an unsafe default ('secret_password')")
            ->assertExitCode(1);
    }

    public function test_rejects_empty_audit_hmac_key(): void
    {
        $this->setValidProductionConfig();
        config([
            'audit.hmac_keys.v1' => '',
            'audit.hmac.current_key' => '',
            'audit.hmac.keys.v1' => '',
        ]);
        $this->setEnv('AUDIT_HMAC_KEY', '');

        $this->artisan('app:check-production-config')
            ->expectsOutputToContain('AUDIT_HMAC_KEY is empty')
            ->assertExitCode(1);
    }

    public function test_rejects_change_me_for_audit_hmac_key(): void
    {
        $this->setValidProductionConfig();
        config([
            'audit.hmac_keys.v1' => 'CHANGE_ME',
            'audit.hmac.current_key' => 'CHANGE_ME',
            'audit.hmac.keys.v1' => 'CHANGE_ME',
        ]);
        $this->setEnv('AUDIT_HMAC_KEY', 'CHANGE_ME');

        $this->artisan('app:check-production-config')
            ->expectsOutputToContain("AUDIT_HMAC_KEY is set to an unsafe default ('CHANGE_ME')")
            ->assertExitCode(1);
    }

    public function test_rejects_empty_audit_pseudonym_salt(): void
    {
        $this->setValidProductionConfig();
        config(['audit.pseudonym_salt' => '']);
        $this->setEnv('AUDIT_PSEUDONYM_SALT', '');

        $this->artisan('app:check-production-config')
            ->expectsOutputToContain('AUDIT_PSEUDONYM_SALT is empty')
            ->assertExitCode(1);
    }

    public function test_rejects_change_me_for_audit_pseudonym_salt(): void
    {
        $this->setValidProductionConfig();
        config(['audit.pseudonym_salt' => 'CHANGE_ME']);
        $this->setEnv('AUDIT_PSEUDONYM_SALT', 'CHANGE_ME');

        $this->artisan('app:check-production-config')
            ->expectsOutputToContain("AUDIT_PSEUDONYM_SALT is set to an unsafe default ('CHANGE_ME')")
            ->assertExitCode(1);
    }

    public function test_rejects_allow_fake_driver_true(): void
    {
        $this->setValidProductionConfig();
        config(['app.allow_fake_driver' => true]);
        $this->setEnv('ALLOW_FAKE_DRIVER', 'true');

        $this->artisan('app:check-production-config')
            ->expectsOutputToContain('ALLOW_FAKE_DRIVER is enabled')
            ->assertExitCode(1);
    }

    public function test_rejects_audit_strict_grants_false(): void
    {
        $this->setValidProductionConfig();
        config(['audit.strict_grants' => false]);
        $this->setEnv('AUDIT_STRICT_GRANTS', 'false');

        $this->artisan('app:check-production-config')
            ->expectsOutputToContain('AUDIT_STRICT_GRANTS is disabled')
            ->assertExitCode(1);
    }

    public function test_rejects_session_secure_cookie_false(): void
    {
        $this->setValidProductionConfig();
        config(['session.secure' => false]);
        $this->setEnv('SESSION_SECURE_COOKIE', 'false');

        $this->artisan('app:check-production-config')
            ->expectsOutputToContain('SESSION_SECURE_COOKIE is disabled or unset')
            ->assertExitCode(1);
    }

    public function test_rejects_session_secure_cookie_unset(): void
    {
        $this->setValidProductionConfig();
        config(['session.secure' => null]);
        putenv('SESSION_SECURE_COOKIE');
        unset($_ENV['SESSION_SECURE_COOKIE'], $_SERVER['SESSION_SECURE_COOKIE']);

        $this->artisan('app:check-production-config')
            ->expectsOutputToContain('SESSION_SECURE_COOKIE is disabled or unset')
            ->assertExitCode(1);
    }

    public function test_rejects_app_url_with_http_scheme(): void
    {
        $this->setValidProductionConfig();
        config(['app.url' => 'http://panel.example.com']);
        $this->setEnv('APP_URL', 'http://panel.example.com');

        $this->artisan('app:check-production-config')
            ->expectsOutputToContain('APP_URL must use HTTPS in production')
            ->assertExitCode(1);
    }

    public function test_rejects_app_url_with_localhost(): void
    {
        $this->setValidProductionConfig();
        config(['app.url' => 'https://localhost']);
        $this->setEnv('APP_URL', 'https://localhost');

        $this->artisan('app:check-production-config')
            ->expectsOutputToContain('APP_URL points to a local/loopback address')
            ->assertExitCode(1);
    }

    public function test_rejects_app_url_with_loopback_ip(): void
    {
        $this->setValidProductionConfig();
        config(['app.url' => 'https://127.0.0.1']);
        $this->setEnv('APP_URL', 'https://127.0.0.1');

        $this->artisan('app:check-production-config')
            ->expectsOutputToContain('APP_URL points to a local/loopback address')
            ->assertExitCode(1);
    }
}
