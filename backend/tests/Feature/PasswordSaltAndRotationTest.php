<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTO\Auth\LoginDTO;
use App\DTO\Auth\RegisterDTO;
use App\DTO\Auth\UpdateProfileDTO;
use App\DTO\User\CreateUserDTO;
use App\DTO\User\UpdateUserDTO;
use App\Models\User;
use App\Services\AuthService;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class PasswordSaltAndRotationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    }

    public function test_generate_base62_salt_produces_24_char_alphanumeric_string(): void
    {
        $authService = app(AuthService::class);
        $salt1 = $authService->generateBase62Salt();
        $salt2 = $authService->generateBase62Salt();

        $this->assertSame(24, strlen($salt1));
        $this->assertMatchesRegularExpression('/^[0-9A-Za-z]{24}$/', $salt1);
        $this->assertNotSame($salt1, $salt2);
    }

    public function test_registration_stores_salt_and_hides_it_from_serialization(): void
    {
        $authService = app(AuthService::class);
        $result = $authService->register(new RegisterDTO(
            name: 'Alice',
            email: 'alice@example.com',
            password: 'UltraSecurePassword!234',
        ));

        $user = $result['user'];
        $this->assertSame(24, strlen($user->password_salt));
        $this->assertMatchesRegularExpression('/^[0-9A-Za-z]{24}$/', $user->password_salt);

        // Plaintext password does not match raw Hash::check because of HMAC-SHA256 pre-hash
        $this->assertFalse(Hash::check('UltraSecurePassword!234', $user->password));
        $this->assertTrue($authService->verifyAndUpgradePassword($user, 'UltraSecurePassword!234'));

        // Hidden in serialization
        $serialized = $user->toArray();
        $this->assertArrayNotHasKey('password', $serialized);
        $this->assertArrayNotHasKey('password_salt', $serialized);
    }

    public function test_password_change_rotates_salt_and_hash_atomically(): void
    {
        $authService = app(AuthService::class);
        $user = User::factory()->withPassword('OldPassword123!')->create([
            'status' => 'active',
        ]);

        $oldSalt = $user->password_salt;
        $oldHash = $user->password;

        $updatedUser = $authService->updateProfile($user, new UpdateProfileDTO(
            name: $user->name,
            email: $user->email,
            currentPassword: 'OldPassword123!',
            password: 'NewPassword456!',
        ));

        $this->assertNotSame($oldSalt, $updatedUser->password_salt);
        $this->assertNotSame($oldHash, $updatedUser->password);
        $this->assertFalse($authService->verifyAndUpgradePassword($updatedUser, 'OldPassword123!'));
        $this->assertTrue($authService->verifyAndUpgradePassword($updatedUser, 'NewPassword456!'));
    }

    public function test_password_reset_rotates_salt_and_hash_atomically(): void
    {
        $authService = app(AuthService::class);
        $user = User::factory()->withPassword('InitialPass123!')->create([
            'email' => 'reset@example.com',
            'status' => 'active',
        ]);

        $oldSalt = $user->password_salt;
        $oldHash = $user->password;
        $token = Password::createToken($user);

        $res = $this->postJson('/api/v1/reset-password', [
            'token' => $token,
            'email' => 'reset@example.com',
            'password' => 'ResetPass789!',
            'password_confirmation' => 'ResetPass789!',
        ]);

        $res->assertOk();
        $user->refresh();

        $this->assertNotSame($oldSalt, $user->password_salt);
        $this->assertNotSame($oldHash, $user->password);
        $this->assertTrue($authService->verifyAndUpgradePassword($user, 'ResetPass789!'));
    }

    public function test_admin_user_service_create_and_update_rotate_salt_atomically(): void
    {
        $userService = app(UserService::class);
        $authService = app(AuthService::class);

        $user = $userService->create(new CreateUserDTO(
            name: 'Managed User',
            email: 'managed@example.com',
            password: 'AdminCreatedPass123!',
            role: 'customer',
            status: 'active',
        ));

        $initialSalt = $user->password_salt;
        $this->assertSame(24, strlen($initialSalt));
        $this->assertTrue($authService->verifyAndUpgradePassword($user, 'AdminCreatedPass123!'));

        $updated = $userService->update($user, new UpdateUserDTO(
            name: 'Managed User',
            email: 'managed@example.com',
            role: 'customer',
            status: 'active',
            password: 'AdminUpdatedPass456!',
        ));

        $this->assertNotSame($initialSalt, $updated->password_salt);
        $this->assertTrue($authService->verifyAndUpgradePassword($updated, 'AdminUpdatedPass456!'));
    }

    public function test_app_key_rotation_via_previous_keys_transparently_rehashes_on_login(): void
    {
        $oldKey = 'base64:'.base64_encode(random_bytes(32));
        $newKey = 'base64:'.base64_encode(random_bytes(32));

        config(['app.key' => $oldKey, 'app.previous_keys' => []]);

        $authService = app(AuthService::class);
        $user = User::factory()->withPassword('KeyRotationPass123!')->create([
            'email' => 'rotate@example.com',
            'status' => 'active',
        ]);

        $oldSalt = $user->password_salt;
        $oldHash = $user->password;

        // Rotate APP_KEY and place oldKey in APP_PREVIOUS_KEYS
        config([
            'app.key' => $newKey,
            'app.previous_keys' => [$oldKey],
        ]);

        // Login should succeed using previous_keys fallback and atomically upgrade salt + hash to newKey
        $loginResult = $authService->login(new LoginDTO(
            email: 'rotate@example.com',
            password: 'KeyRotationPass123!',
        ));

        $this->assertNotEmpty($loginResult['token']);

        $user->refresh();
        $this->assertNotSame($oldSalt, $user->password_salt);
        $this->assertNotSame($oldHash, $user->password);

        // Now remove previous_keys completely; user can still log in with the new key
        config(['app.previous_keys' => []]);
        $this->assertTrue($authService->verifyAndUpgradePassword($user, 'KeyRotationPass123!'));
    }
}
