<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\CheckProductionConfigCommand;
use Tests\TestCase;

final class CheckProductionConfigTest extends TestCase
{
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
            'app.run_seeders' => false,
            'database.connections.mysql.password' => 'super_secure_random_db_pass_1234',
            'database.connections.mysql.root_password' => 'super_secure_random_root_pass_5678',
            'reverb.apps.apps.0.secret' => 'super_secure_reverb_secret_9999',
            'reverb.apps.apps.0.allowed_origins' => ['https://panel.example.com'],
            'database.redis.default.password' => 'super_secure_redis_password_9876',
        ]);

        $this->setEnv('APP_KEY', $validKey);
        $this->setEnv('APP_DEBUG', 'false');
        $this->setEnv('RUN_SEEDERS', 'false');
        $this->setEnv('DB_PASSWORD', 'super_secure_random_db_pass_1234');
        $this->setEnv('DB_ROOT_PASSWORD', 'super_secure_random_root_pass_5678');
        $this->setEnv('REVERB_APP_SECRET', 'super_secure_reverb_secret_9999');
        $this->setEnv('REVERB_ALLOWED_ORIGINS', 'https://panel.example.com');
        $this->setEnv('REDIS_PASSWORD', 'super_secure_redis_password_9876');
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
}
