<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\AuditContext;
use App\DTO\Auth\LoginDTO;
use App\DTO\Auth\RegisterDTO;
use App\DTO\Auth\UpdateProfileDTO;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AuditAuthCoverageTest extends TestCase
{
    use AuditAssertionTrait;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        app(AuditContext::class)->setRequestId('req-auth-test-1234');
    }

    public function test_auth_login_success(): void
    {
        $user = User::factory()->create([
            'email' => 'login.success@example.com',
            'password' => Hash::make('SecretPass123!'),
            'status' => 'active',
        ]);

        $service = app(AuthService::class);
        $result = $service->login(new LoginDTO(
            email: 'login.success@example.com',
            password: 'SecretPass123!',
        ));

        $this->assertNotEmpty($result['token']);

        $row = $this->assertAuditRow(
            action: 'auth.login.success',
            outcome: 'success',
            tenantUserId: $user->id,
            visibility: 'tenant',
            requestId: 'req-auth-test-1234',
        );

        $context = json_decode((string) $row->context, true);
        $this->assertSame('login.success@example.com', $context['pii']['email']);
    }

    public function test_auth_login_failed_with_unknown_email(): void
    {
        $service = app(AuthService::class);

        try {
            $service->login(new LoginDTO(
                email: 'UNKNOWN.USER@example.com',
                password: 'WrongPassword!',
            ));
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException) {
            // expected
        }

        $row = $this->assertAuditRow(
            action: 'auth.login.failed',
            outcome: 'failed',
            tenantUserId: null,
            visibility: 'admin',
            requestId: 'req-auth-test-1234',
        );

        $this->assertNull($row->actor_id);
        $context = json_decode((string) $row->context, true);
        $this->assertSame('unknown.user@example.com', $context['pii']['attempted_email']);
    }

    public function test_auth_login_failed_with_known_user(): void
    {
        $user = User::factory()->create([
            'email' => 'known.user@example.com',
            'password' => Hash::make('CorrectPass123!'),
            'status' => 'active',
        ]);

        $service = app(AuthService::class);

        try {
            $service->login(new LoginDTO(
                email: 'KNOWN.USER@EXAMPLE.COM',
                password: 'WrongPassword!',
            ));
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException) {
            // expected
        }

        $row = $this->assertAuditRow(
            action: 'auth.login.failed',
            outcome: 'failed',
            tenantUserId: $user->id,
            visibility: 'tenant',
            requestId: 'req-auth-test-1234',
        );

        $this->assertNull($row->actor_id);
        $context = json_decode((string) $row->context, true);
        $this->assertSame('known.user@example.com', $context['pii']['attempted_email']);
    }

    public function test_auth_login_throttled_hooks_lockout_event(): void
    {
        $user = User::factory()->create([
            'email' => 'throttled@example.com',
            'status' => 'active',
        ]);

        $request = Request::create('/api/login', 'POST', [
            'email' => 'THROTTLED@example.com',
        ]);

        event(new Lockout($request));

        $row = $this->assertAuditRow(
            action: 'auth.login.throttled',
            outcome: 'denied',
            tenantUserId: $user->id,
            visibility: 'tenant',
            requestId: 'req-auth-test-1234',
        );

        $context = json_decode((string) $row->context, true);
        $this->assertSame('throttled@example.com', $context['pii']['attempted_email']);
    }

    public function test_auth_register_and_logout(): void
    {
        $service = app(AuthService::class);

        $result = $service->register(new RegisterDTO(
            name: 'New Registered User',
            email: 'registered@example.com',
            password: 'SecurePassword123!',
        ));

        /** @var User $newUser */
        $newUser = $result['user'];

        $row = $this->assertAuditRow(
            action: 'auth.register',
            outcome: 'success',
            tenantUserId: $newUser->id,
            visibility: 'tenant',
            requestId: 'req-auth-test-1234',
        );

        $context = json_decode((string) $row->context, true);
        $this->assertSame('registered@example.com', $context['pii']['email']);
        $this->assertSame('New Registered User', $context['pii']['name']);

        // Now logout
        $newUser->createToken('test-token');
        $service->logout($newUser);

        $logoutRow = $this->assertAuditRow(
            action: 'auth.logout',
            outcome: 'success',
            tenantUserId: $newUser->id,
            visibility: 'tenant',
            requestId: 'req-auth-test-1234',
        );

        $logoutContext = json_decode((string) $logoutRow->context, true);
        $this->assertSame('registered@example.com', $logoutContext['pii']['email']);
    }

    public function test_auth_password_changed_and_email_changed(): void
    {
        $user = User::factory()->create([
            'name' => 'Profile User',
            'email' => 'old.email@example.com',
            'password' => Hash::make('CurrentPass123!'),
            'status' => 'active',
        ]);
        $user->createToken('session-1');

        $service = app(AuthService::class);

        $service->updateProfile($user, new UpdateProfileDTO(
            name: 'Updated Name',
            email: 'new.email@example.com',
            currentPassword: 'CurrentPass123!',
            password: 'NewSecretPass456!',
        ));

        // Password changed
        $pwdRow = $this->assertAuditRow(
            action: 'auth.password.changed',
            outcome: 'success',
            tenantUserId: $user->id,
            visibility: 'tenant',
            requestId: 'req-auth-test-1234',
        );
        $pwdContext = json_decode((string) $pwdRow->context, true);
        $this->assertSame('old.email@example.com', $pwdContext['pii']['email']);

        // Tokens revoked
        $tokenRow = $this->assertAuditRow(
            action: 'auth.tokens.revoked',
            outcome: 'success',
            tenantUserId: $user->id,
            visibility: 'tenant',
            requestId: 'req-auth-test-1234',
        );
        $tokenContext = json_decode((string) $tokenRow->context, true);
        $this->assertSame('password', $tokenContext['reason']);

        // Email changed
        $emailRow = $this->assertAuditRow(
            action: 'auth.email.changed',
            outcome: 'success',
            tenantUserId: $user->id,
            visibility: 'tenant',
            requestId: 'req-auth-test-1234',
        );
        $emailContext = json_decode((string) $emailRow->context, true);
        $this->assertSame('old.email@example.com', $emailContext['pii']['old_email']);
        $this->assertSame('new.email@example.com', $emailContext['pii']['new_email']);
    }
}
