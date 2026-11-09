<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\TestCase;

final class AuditCommandGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $kernel = $this->app->make(Kernel::class);
        if ($kernel instanceof ConsoleKernel) {
            $kernel->rerouteSymfonyCommandEvents();
        }

        // Register a test audit command
        Artisan::command('audit:test-command', function () {
            $this->info('Audit test executed successfully');
        });
    }

    public function test_audit_command_runs_in_non_production_with_defaults(): void
    {
        $this->app->detectEnvironment(fn () => 'testing');

        config([
            'audit.hmac.current_key' => 'CHANGE_ME',
            'audit.pseudonym_salt' => 'CHANGE_ME',
        ]);

        $this->artisan('audit:test-command')
            ->expectsOutputToContain('Audit test executed successfully')
            ->assertExitCode(0);
    }

    public function test_audit_command_fails_in_production_with_change_me_hmac_key(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        config([
            'audit.hmac.current_key' => 'CHANGE_ME',
            'audit.pseudonym_salt' => bin2hex(random_bytes(32)),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Refusing to run audit command 'audit:test-command' in production: AUDIT_HMAC_KEY is empty or equals the example value ('CHANGE_ME')");

        $this->artisan('audit:test-command');
    }

    public function test_audit_command_fails_in_production_with_empty_pseudonym_salt(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        config([
            'audit.hmac.current_key' => bin2hex(random_bytes(32)),
            'audit.pseudonym_salt' => '',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Refusing to run audit command 'audit:test-command' in production: AUDIT_PSEUDONYM_SALT is empty or equals the example value (empty)");

        $this->artisan('audit:test-command');
    }

    public function test_audit_command_succeeds_in_production_with_valid_keys(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        config([
            'audit.hmac.current_key' => bin2hex(random_bytes(32)),
            'audit.pseudonym_salt' => bin2hex(random_bytes(32)),
        ]);

        $this->artisan('audit:test-command')
            ->expectsOutputToContain('Audit test executed successfully')
            ->assertExitCode(0);
    }
}
