<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\QueuedVerifyEmailNotification;
use App\Services\AuthService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AuthenticationHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
        RateLimiter::clear('auth-login:test@example.com|127.0.0.1');
        RateLimiter::clear('auth-register:127.0.0.1');
    }

    /**
     * Prepare a stateful browser session context with Origin and CSRF cookies for auth endpoints.
     *
     * @return array<string, string>
     */
    private function prepareStatefulHeaders(): array
    {
        $csrfResponse = $this->withHeader('Origin', 'http://localhost')
            ->get('/sanctum/csrf-cookie');
        $csrfResponse->assertNoContent();

        $cookies = [];
        foreach ($csrfResponse->headers->getCookies() as $cookie) {
            $value = $cookie->getValue();
            if ($value !== null) {
                $cookies[$cookie->getName()] = $value;
            }
        }

        $this->withCredentials()->withUnencryptedCookies($cookies);

        $headers = ['Origin' => 'http://localhost'];
        $xsrfToken = $csrfResponse->getCookie('XSRF-TOKEN')?->getValue();
        if (is_string($xsrfToken) && $xsrfToken !== '') {
            $headers['X-XSRF-TOKEN'] = $xsrfToken;
        }

        return $headers;
    }

    public function test_login_rate_limiting_returns_429_on_sixth_attempt(): void
    {
        $headers = $this->prepareStatefulHeaders();
        $payload = [
            'email' => 'test@example.com',
            'password' => 'WrongPassword123!',
        ];

        for ($i = 0; $i < 5; $i++) {
            $response = $this->withHeaders($headers)->postJson('/api/v1/login', $payload);
            $response->assertStatus(422);
        }

        $sixthResponse = $this->withHeaders($headers)->postJson('/api/v1/login', $payload);
        $sixthResponse->assertStatus(429);
    }

    public function test_register_rate_limiting_returns_429_on_sixth_attempt(): void
    {
        Notification::fake();
        $headers = $this->prepareStatefulHeaders();

        for ($i = 0; $i < 5; $i++) {
            $response = $this->withHeaders($headers)->postJson('/api/v1/register', [
                'name' => "User {$i}",
                'email' => "user{$i}@example.com",
                'password' => 'StrongPassword123!',
                'password_confirmation' => 'StrongPassword123!',
            ]);
            $response->assertStatus(201);
            $response->assertJsonMissingPath('token');
        }

        $sixthResponse = $this->withHeaders($headers)->postJson('/api/v1/register', [
            'name' => 'User 6',
            'email' => 'user6@example.com',
            'password' => 'StrongPassword123!',
            'password_confirmation' => 'StrongPassword123!',
        ]);
        $sixthResponse->assertStatus(429);
    }

    public function test_email_change_without_current_password_returns_422(): void
    {
        /** @var User $user */
        $user = User::factory()->withPassword('Secret12345!')->create([
            'email' => 'original@example.com',
            'status' => 'active',
        ]);
        $user->assignRole('customer');

        $response = $this->actingAs($user)->putJson('/api/v1/me', [
            'name' => 'Updated Name',
            'email' => 'newaddress@example.com',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['current_password']);
    }

    public function test_email_change_with_invalid_current_password_returns_422(): void
    {
        /** @var User $user */
        $user = User::factory()->withPassword('Secret12345!')->create([
            'email' => 'original@example.com',
            'status' => 'active',
        ]);
        $user->assignRole('customer');

        $response = $this->actingAs($user)->putJson('/api/v1/me', [
            'name' => 'Updated Name',
            'email' => 'newaddress@example.com',
            'current_password' => 'IncorrectPassword!',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['current_password']);
    }

    public function test_email_change_resets_verification_and_sends_notification(): void
    {
        Notification::fake();

        /** @var User $user */
        $user = User::factory()->withPassword('Secret12345!')->create([
            'email' => 'original@example.com',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $user->assignRole('customer');

        $response = $this->actingAs($user)->putJson('/api/v1/me', [
            'name' => 'Updated Name',
            'email' => 'newaddress@example.com',
            'current_password' => 'Secret12345!',
        ]);

        $response->assertStatus(200);

        $user->refresh();
        $this->assertEquals('newaddress@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
        $this->assertEquals('pending', $user->status);

        Notification::assertSentTo($user, QueuedVerifyEmailNotification::class);
    }

    public function test_email_change_keeps_status_active_when_registration_verification_disabled(): void
    {
        Notification::fake();

        SystemSetting::updateOrCreate(
            ['key' => 'registration_verification_enabled'],
            ['value' => false]
        );

        /** @var User $user */
        $user = User::factory()->withPassword('Secret12345!')->create([
            'email' => 'original@example.com',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $user->assignRole('customer');

        $response = $this->actingAs($user)->putJson('/api/v1/me', [
            'name' => 'Updated Name',
            'email' => 'newaddress@example.com',
            'current_password' => 'Secret12345!',
        ]);

        $response->assertStatus(200);

        $user->refresh();
        $this->assertEquals('newaddress@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
        $this->assertEquals('active', $user->status);

        Notification::assertSentTo($user, QueuedVerifyEmailNotification::class);
    }

    public function test_database_seeder_throws_in_production_environment(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DatabaseSeeder cannot be run in production environment.');

        $seeder = new DatabaseSeeder;
        $seeder->run();
    }

    public function test_admin_create_command_creates_user_with_admin_role_and_generated_password(): void
    {
        $email = 'cmd_admin@example.com';

        $this->artisan('admin:create', [
            'email' => $email,
            '--name' => 'Super Administrator',
        ])
            ->expectsOutputToContain("Admin user '{$email}' created successfully.")
            ->expectsOutputToContain('Generated password:')
            ->assertExitCode(0);

        /** @var User|null $admin */
        $admin = User::where('email', $email)->first();
        $this->assertNotNull($admin);
        $this->assertSame('Super Administrator', $admin->name);
        $this->assertSame('active', $admin->status->getValue());
        $this->assertNotNull($admin->email_verified_at);
        $this->assertTrue($admin->hasRole('admin'));
        $this->assertSame(-1, $admin->quota?->max_vms);
    }

    public function test_admin_create_command_with_explicit_password(): void
    {
        $email = 'explicit_admin@example.com';
        $password = 'SuperSecretP@ss123!';

        $this->artisan('admin:create', [
            'email' => $email,
            '--password' => $password,
        ])
            ->expectsOutputToContain("Admin user '{$email}' created successfully.")
            ->doesntExpectOutputToContain('Generated password:')
            ->assertExitCode(0);

        /** @var User|null $admin */
        $admin = User::where('email', $email)->first();
        $this->assertNotNull($admin);
        $this->assertTrue(app(AuthService::class)->verifyAndUpgradePassword($admin, $password));
        $this->assertTrue($admin->hasRole('admin'));
    }
}
