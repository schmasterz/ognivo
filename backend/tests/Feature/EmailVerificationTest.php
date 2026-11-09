<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\QuotaGroup;
use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\QueuedVerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
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

    public function test_user_registration_sets_status_pending_and_sends_verification_email_with_frontend_url(): void
    {
        Notification::fake();
        SystemSetting::updateOrCreate(['key' => 'frontend_url'], ['value' => 'https://panel.example.com']);

        $res = $this->postJson('/api/register', [
            'name' => 'John Doe',
            'email' => 'john.doe@example.com',
            'password' => 'SecurePass123!@#',
        ]);

        $res->assertCreated();
        $res->assertJsonPath('user.status', 'pending');
        $res->assertJsonPath('user.email_verified_at', null);
        $res->assertJsonStructure(['message', 'user']);

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
        $user = User::factory()->create([
            'email' => 'pending@example.com',
            'password' => Hash::make('password123'),
            'status' => 'pending',
            'email_verified_at' => null,
        ]);

        $res = $this->postJson('/api/login', [
            'email' => 'pending@example.com',
            'password' => 'password123',
        ]);

        $res->assertUnprocessable();
        $this->assertStringContainsString('pending', (string) json_encode($res->json('errors')));
    }

    public function test_suspended_or_inactive_user_cannot_login(): void
    {
        User::factory()->create([
            'email' => 'suspended@example.com',
            'password' => Hash::make('password123'),
            'status' => 'suspended',
        ]);

        $resSuspended = $this->postJson('/api/login', [
            'email' => 'suspended@example.com',
            'password' => 'password123',
        ]);
        $resSuspended->assertUnprocessable();

        User::factory()->create([
            'email' => 'inactive@example.com',
            'password' => Hash::make('password123'),
            'status' => 'inactive',
        ]);

        $resInactive = $this->postJson('/api/login', [
            'email' => 'inactive@example.com',
            'password' => 'password123',
        ]);
        $resInactive->assertUnprocessable();
    }

    public function test_user_can_verify_email_via_api_with_valid_signature_and_status_becomes_active(): void
    {
        $user = User::factory()->create([
            'email' => 'verify.me@example.com',
            'password' => Hash::make('password123'),
            'status' => 'pending',
            'email_verified_at' => null,
        ]);

        $id = $user->id;
        $hash = sha1($user->getEmailForVerification());
        $expires = now()->addHours(24)->timestamp;
        $signature = hash_hmac('sha256', "verify:{$id}:{$hash}:{$expires}", (string) config('app.key'));

        $res = $this->postJson('/api/email/verify', [
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
        $loginRes = $this->postJson('/api/login', [
            'email' => 'verify.me@example.com',
            'password' => 'password123',
        ]);
        $loginRes->assertOk();
        $loginRes->assertJsonStructure(['token', 'user']);
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

        $res = $this->postJson('/api/email/verify', [
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

        $res = $this->postJson('/api/email/verify', [
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

        $res = $this->postJson('/api/email/verify', [
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

        $res = $this->postJson('/api/email/resend-verification', [
            'email' => 'resend@example.com',
        ]);

        $res->assertOk();
        $res->assertJsonStructure(['message']);

        Notification::assertSentTo($user, QueuedVerifyEmailNotification::class);
    }

    public function test_resend_verification_for_unknown_or_active_user_returns_generic_message(): void
    {
        Notification::fake();

        $res = $this->postJson('/api/email/resend-verification', [
            'email' => 'unknown@example.com',
        ]);

        $res->assertOk();
        $res->assertJsonStructure(['message']);
        Notification::assertNothingSent();
    }
}
