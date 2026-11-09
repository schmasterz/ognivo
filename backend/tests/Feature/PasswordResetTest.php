<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\QueuedResetPasswordNotification;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

final class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_link_is_sent_to_existing_user_with_frontend_url(): void
    {
        Notification::fake();
        SystemSetting::updateOrCreate(['key' => 'frontend_url'], ['value' => 'https://panel.example.com']);

        $user = User::factory()->create(['email' => 'john@example.com']);

        $res = $this->postJson('/api/forgot-password', ['email' => 'john@example.com']);
        $res->assertOk();

        Notification::assertSentTo($user, QueuedResetPasswordNotification::class, function (QueuedResetPasswordNotification $notification) use ($user): bool {
            $mail = $notification->toMail($user);

            return str_starts_with((string) $mail->actionUrl, 'https://panel.example.com/reset-password?token=');
        });
    }

    public function test_unknown_email_returns_same_generic_response(): void
    {
        Notification::fake();

        $res = $this->postJson('/api/forgot-password', ['email' => 'nobody@example.com']);
        $res->assertOk();
        $res->assertJsonStructure(['message']);

        Notification::assertNothingSent();
    }

    public function test_user_can_reset_password_with_valid_token_and_old_tokens_are_revoked(): void
    {
        $user = User::factory()->create(['email' => 'jane@example.com']);
        $user->createToken('old-session');
        $token = Password::createToken($user);

        $res = $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => 'jane@example.com',
            'password' => 'NewSecurePass123!',
            'password_confirmation' => 'NewSecurePass123!',
        ]);

        $res->assertOk();
        $user->refresh();
        $this->assertTrue(app(AuthService::class)->verifyAndUpgradePassword($user, 'NewSecurePass123!'));
        $this->assertSame(0, $user->tokens()->count());

        // Token is single-use
        $again = $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => 'jane@example.com',
            'password' => 'AnotherPass123!',
            'password_confirmation' => 'AnotherPass123!',
        ]);
        $again->assertStatus(422);
    }

    public function test_invalid_token_is_rejected(): void
    {
        User::factory()->create(['email' => 'bob@example.com']);

        $res = $this->postJson('/api/reset-password', [
            'token' => 'invalid-token',
            'email' => 'bob@example.com',
            'password' => 'NewSecurePass123!',
            'password_confirmation' => 'NewSecurePass123!',
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['token']);
    }

    public function test_password_confirmation_must_match(): void
    {
        $user = User::factory()->create(['email' => 'eve@example.com']);
        $token = Password::createToken($user);

        $res = $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => 'eve@example.com',
            'password' => 'NewSecurePass123!',
            'password_confirmation' => 'Mismatch123!',
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['password']);
    }
}
