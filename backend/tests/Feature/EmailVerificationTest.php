<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\QuotaGroup;
use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\QueuedVerifyEmailNotification;
use App\States\User\Deleting;
use App\States\User\DeletionFailed;
use App\States\User\Inactive;
use App\States\User\Suspended;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure default quota group exists for registrations
        QuotaGroup::create([
            'name' => 'Default Group',
            'is_default' => true,
            'max_vms' => 2,
            'max_cpu_cores' => 4,
            'max_ram_mb' => 4096,
            'max_storage_gb' => 80,
            'max_ips' => 1,
        ]);

        Role::firstOrCreate(['name' => 'customer']);
        Role::firstOrCreate(['name' => 'admin']);
    }

    /**
     * Send a stateful JSON POST request with Origin and CSRF headers obtained from /sanctum/csrf-cookie.
     *
     * @param  array<string, mixed>  $data
     */
    private function postStatefulJson(string $uri, array $data = []): TestResponse
    {
        $csrfResponse = $this->withHeader('Origin', 'http://localhost')
            ->getJson('/sanctum/csrf-cookie');
        $csrfResponse->assertNoContent();

        $xsrfCookie = $csrfResponse->getCookie('XSRF-TOKEN', false);
        $xsrfToken = $xsrfCookie !== null ? $xsrfCookie->getValue() : '';

        $sessionCookieName = (string) config('session.cookie');
        $sessionCookie = $csrfResponse->getCookie($sessionCookieName, false);

        $request = $this->withHeaders([
            'Origin' => 'http://localhost',
            'X-XSRF-TOKEN' => $xsrfToken,
        ]);

        if ($sessionCookie !== null) {
            $request = $request->withCredentials()
                ->withUnencryptedCookie($sessionCookieName, $sessionCookie->getValue());
        }

        return $request->postJson($uri, $data);
    }

    public function test_user_registration_sets_status_pending_and_sends_verification_email_with_frontend_url(): void
    {
        Notification::fake();
        SystemSetting::updateOrCreate(['key' => 'frontend_url'], ['value' => 'https://panel.example.com']);

        $res = $this->postStatefulJson('/api/v1/register', [
            'name' => 'John Doe',
            'email' => 'john.doe@example.com',
            'password' => 'SecurePass123!@#',
        ]);

        $res->assertCreated();
        $res->assertJsonPath('user.status', 'pending');
        $res->assertJsonPath('user.email_verified_at', null);
        $res->assertJsonStructure(['message', 'user']);
        $res->assertJsonMissingPath('token');

        $user = User::where('email', 'john.doe@example.com')->firstOrFail();
        $this->assertEquals('pending', $user->status);
        $this->assertNull($user->email_verified_at);

        Notification::assertSentTo($user, QueuedVerifyEmailNotification::class, function (QueuedVerifyEmailNotification $notification) use ($user): bool {
            $mail = $notification->toMail($user);

            return str_starts_with((string) $mail->actionUrl, 'https://panel.example.com/verify-email?id='.$user->id);
        });
    }

    public function test_pending_user_cannot_login_until_verified(): void
    {
        $user = User::factory()->withPassword('password123')->create([
            'email' => 'pending@example.com',
            'status' => 'pending',
            'email_verified_at' => null,
        ]);

        $res = $this->postStatefulJson('/api/v1/login', [
            'email' => 'pending@example.com',
            'password' => 'password123',
        ]);

        $res->assertUnprocessable();
        $this->assertStringContainsString('pending', (string) json_encode($res->json('errors')));
    }

    public function test_suspended_or_inactive_user_cannot_login(): void
    {
        User::factory()->withPassword('password123')->create([
            'email' => 'suspended@example.com',
            'status' => 'suspended',
        ]);

        $resSuspended = $this->postStatefulJson('/api/v1/login', [
            'email' => 'suspended@example.com',
            'password' => 'password123',
        ]);
        $resSuspended->assertUnprocessable();

        User::factory()->withPassword('password123')->create([
            'email' => 'inactive@example.com',
            'status' => 'inactive',
        ]);

        $resInactive = $this->postStatefulJson('/api/v1/login', [
            'email' => 'inactive@example.com',
            'password' => 'password123',
        ]);
        $resInactive->assertUnprocessable();
    }

    public function test_user_can_verify_email_via_api_with_valid_signature_and_status_becomes_active(): void
    {
        $user = User::factory()->withPassword('password123')->create([
            'email' => 'verify.me@example.com',
            'status' => 'pending',
            'email_verified_at' => null,
        ]);

        $id = $user->id;
        $hash = sha1($user->getEmailForVerification());
        $expires = now()->addHours(24)->timestamp;
        $signature = hash_hmac('sha256', "verify:{$id}:{$hash}:{$expires}", (string) config('app.key'));

        $res = $this->postJson('/api/v1/email/verify', [
            'id' => $id,
            'hash' => $hash,
            'expires' => $expires,
            'signature' => $signature,
        ]);

        $res->assertOk();
        $res->assertJsonPath('already_verified', false);

        $user->refresh();
        $this->assertEquals('active', $user->status);
        $this->assertNotNull($user->email_verified_at);

        // Now user can log in
        $loginRes = $this->postStatefulJson('/api/v1/login', [
            'email' => 'verify.me@example.com',
            'password' => 'password123',
        ]);
        $loginRes->assertOk();
        $loginRes->assertJsonStructure(['user']);
        $loginRes->assertJsonMissingPath('token');
    }

    public function test_verification_with_expired_link_fails(): void
    {
        $user = User::factory()->create([
            'email' => 'expired@example.com',
            'status' => 'pending',
            'email_verified_at' => null,
        ]);

        $id = $user->id;
        $hash = sha1($user->getEmailForVerification());
        $expires = now()->subMinutes(10)->timestamp; // Expired!
        $signature = hash_hmac('sha256', "verify:{$id}:{$hash}:{$expires}", (string) config('app.key'));

        $res = $this->postJson('/api/v1/email/verify', [
            'id' => $id,
            'hash' => $hash,
            'expires' => $expires,
            'signature' => $signature,
        ]);

        $res->assertUnprocessable();
        $this->assertEquals('pending', $user->fresh()->status);
    }

    public function test_verification_with_tampered_signature_fails(): void
    {
        $user = User::factory()->create([
            'email' => 'tampered@example.com',
            'status' => 'pending',
            'email_verified_at' => null,
        ]);

        $id = $user->id;
        $hash = sha1($user->getEmailForVerification());
        $expires = now()->addHours(24)->timestamp;
        $signature = 'invalid-signature-12345';

        $res = $this->postJson('/api/v1/email/verify', [
            'id' => $id,
            'hash' => $hash,
            'expires' => $expires,
            'signature' => $signature,
        ]);

        $res->assertUnprocessable();
        $this->assertEquals('pending', $user->fresh()->status);
    }

    public function test_already_verified_user_returns_already_verified(): void
    {
        $user = User::factory()->create([
            'email' => 'active@example.com',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $id = $user->id;
        $hash = sha1($user->getEmailForVerification());
        $expires = now()->addHours(24)->timestamp;
        $signature = hash_hmac('sha256', "verify:{$id}:{$hash}:{$expires}", (string) config('app.key'));

        $res = $this->postJson('/api/v1/email/verify', [
            'id' => $id,
            'hash' => $hash,
            'expires' => $expires,
            'signature' => $signature,
        ]);

        $res->assertOk();
        $res->assertJsonPath('already_verified', true);
    }

    public function test_pending_user_can_request_resend_verification_email(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'resend@example.com',
            'status' => 'pending',
            'email_verified_at' => null,
        ]);

        $res = $this->postJson('/api/v1/email/resend-verification', [
            'email' => 'resend@example.com',
        ]);

        $res->assertOk();
        $res->assertJsonStructure(['message']);

        Notification::assertSentTo($user, QueuedVerifyEmailNotification::class);
    }

    public function test_resend_verification_for_unknown_or_active_user_returns_generic_message(): void
    {
        Notification::fake();

        $res = $this->postJson('/api/v1/email/resend-verification', [
            'email' => 'unknown@example.com',
        ]);

        $res->assertOk();
        $res->assertJsonStructure(['message']);
        Notification::assertNothingSent();
    }

    public function test_non_pending_blocked_users_cannot_reactivate_via_email_verification_post_or_get(): void
    {
        foreach ([Suspended::class, Inactive::class, Deleting::class, DeletionFailed::class] as $idx => $stateClass) {
            $server = ['REMOTE_ADDR' => sprintf('10.10.0.%d', $idx + 10)];
            $user = User::factory()->create([
                'email' => sprintf('blocked-%s@example.com', $stateClass::$name),
                'status' => $stateClass,
                'email_verified_at' => null,
            ]);

            $id = $user->id;
            $hash = sha1($user->getEmailForVerification());
            $expires = now()->addHours(24)->timestamp;
            $signature = hash_hmac('sha256', "verify:{$id}:{$hash}:{$expires}", (string) config('app.key'));

            $postRes = $this->withServerVariables($server)->postJson('/api/v1/email/verify', [
                'id' => $id,
                'hash' => $hash,
                'expires' => $expires,
                'signature' => $signature,
            ]);

            $postRes->assertForbidden();
            $user->refresh();
            $this->assertTrue($user->status->equals($stateClass));
            $this->assertNull($user->email_verified_at);

            $getJsonRes = $this->withServerVariables($server)->getJson(sprintf(
                '/api/v1/email/verify/%d/%s?expires=%d&signature=%s',
                $id,
                $hash,
                $expires,
                $signature
            ));

            $getJsonRes->assertForbidden();
            $user->refresh();
            $this->assertTrue($user->status->equals($stateClass));
            $this->assertNull($user->email_verified_at);

            $getRedirectRes = $this->withServerVariables($server)->get(sprintf(
                '/api/v1/email/verify/%d/%s?expires=%d&signature=%s',
                $id,
                $hash,
                $expires,
                $signature
            ));

            $getRedirectRes->assertRedirect();
            $this->assertStringContainsString('error=account_inactive', (string) $getRedirectRes->headers->get('Location'));
            $user->refresh();
            $this->assertTrue($user->status->equals($stateClass));
            $this->assertNull($user->email_verified_at);
        }
    }
}
