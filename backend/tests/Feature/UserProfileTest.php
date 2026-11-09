<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class UserProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $this->user = User::factory()->create([
            'name' => 'John Doe',
            'email' => 'john@test.local',
            'password' => Hash::make('CurrentSecret123!'),
        ]);
        $this->user->assignRole('customer');
    }

    public function test_user_can_update_name_and_email(): void
    {
        $response = $this->actingAs($this->user)
            ->putJson('/api/me', [
                'name' => 'Johnathan Doe',
                'email' => 'john.doe.updated@test.local',
                'current_password' => 'CurrentSecret123!',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'id' => $this->user->id,
            'name' => 'Johnathan Doe',
            'email' => 'john.doe.updated@test.local',
        ]);
    }

    public function test_user_can_change_password_with_valid_current_password(): void
    {
        $response = $this->actingAs($this->user)
            ->putJson('/api/me', [
                'name' => 'John Doe',
                'email' => 'john@test.local',
                'current_password' => 'CurrentSecret123!',
                'password' => 'BrandNewPassword999!',
                'password_confirmation' => 'BrandNewPassword999!',
            ]);

        $response->assertStatus(200);

        $this->user->refresh();
        $this->assertTrue(Hash::check('BrandNewPassword999!', $this->user->password));
    }

    public function test_user_cannot_change_password_with_invalid_current_password(): void
    {
        $response = $this->actingAs($this->user)
            ->putJson('/api/me', [
                'name' => 'John Doe',
                'email' => 'john@test.local',
                'current_password' => 'WrongPassword',
                'password' => 'BrandNewPassword999!',
                'password_confirmation' => 'BrandNewPassword999!',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['current_password']);
    }
}
