<?php

declare(strict_types=1);

use App\Models\Image;
use App\Models\User;
use App\Models\VirtualMachine;
use App\States\Vm\Deleted;
use App\States\Vm\Running;
use App\States\Vm\Stopped;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $customerRole = Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole($adminRole);

    $this->customer = User::factory()->create();
    $this->customer->assignRole($customerRole);
});

test('deleting an image used by active virtual machines fails with 422', function (): void {
    $image = Image::factory()->create([
        'name' => 'custom/debian-in-use',
        'alias' => 'Debian 12 In Use',
    ]);

    VirtualMachine::factory()->create([
        'user_id' => $this->customer->id,
        'os_image' => $image->name,
        'status' => Running::class,
    ]);

    VirtualMachine::factory()->create([
        'user_id' => $this->customer->id,
        'os_image' => $image->name,
        'status' => Stopped::class,
    ]);

    VirtualMachine::factory()->create([
        'user_id' => $this->customer->id,
        'os_image' => $image->name,
        'status' => Deleted::class,
    ]);

    $response = $this->actingAs($this->admin)->deleteJson("/api/admin/images/{$image->id}");

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['image']);

    $imageErrors = $response->json('errors.image');
    expect($imageErrors)->toBeArray()
        ->and($imageErrors[0])->toContain('2 active virtual machine(s)')
        ->and($imageErrors[0])->toContain("Cannot delete image '{$image->alias}'");

    $this->assertDatabaseHas('images', ['id' => $image->id]);
});

test('deleting an image referenced only by deleted virtual machines succeeds with 200', function (): void {
    $image = Image::factory()->create([
        'name' => 'custom/ubuntu-only-deleted-vms',
        'alias' => 'Ubuntu Only Deleted',
    ]);

    VirtualMachine::factory()->create([
        'user_id' => $this->customer->id,
        'os_image' => $image->name,
        'status' => Deleted::class,
    ]);

    $response = $this->actingAs($this->admin)->deleteJson("/api/admin/images/{$image->id}");

    $response->assertStatus(200);

    $this->assertDatabaseMissing('images', ['id' => $image->id]);
});

test('deleting an image with no virtual machines succeeds with 200', function (): void {
    $image = Image::factory()->create([
        'name' => 'custom/alpine-unused',
        'alias' => 'Alpine Unused',
    ]);

    $response = $this->actingAs($this->admin)->deleteJson("/api/admin/images/{$image->id}");

    $response->assertStatus(200);

    $this->assertDatabaseMissing('images', ['id' => $image->id]);
});

test('admin image index returns active_vms_count excluding deleted virtual machines', function (): void {
    $activeImage = Image::factory()->create([
        'name' => 'custom/debian-active-count',
        'alias' => 'Debian Active Count',
    ]);

    $deletedOnlyImage = Image::factory()->create([
        'name' => 'custom/alpine-deleted-only-count',
        'alias' => 'Alpine Deleted Only',
    ]);

    VirtualMachine::factory()->create([
        'user_id' => $this->customer->id,
        'os_image' => $activeImage->name,
        'status' => Running::class,
    ]);

    VirtualMachine::factory()->create([
        'user_id' => $this->customer->id,
        'os_image' => $activeImage->name,
        'status' => Stopped::class,
    ]);

    VirtualMachine::factory()->create([
        'user_id' => $this->customer->id,
        'os_image' => $activeImage->name,
        'status' => Deleted::class,
    ]);

    VirtualMachine::factory()->create([
        'user_id' => $this->customer->id,
        'os_image' => $deletedOnlyImage->name,
        'status' => Deleted::class,
    ]);

    $response = $this->actingAs($this->admin)->getJson('/api/admin/images');

    $response->assertStatus(200);

    $items = collect($response->json('data'));
    $activeItem = $items->firstWhere('id', $activeImage->id);
    $deletedOnlyItem = $items->firstWhere('id', $deletedOnlyImage->id);

    expect($activeItem)->not->toBeNull()
        ->and($activeItem['active_vms_count'])->toBe(2)
        ->and($deletedOnlyItem)->not->toBeNull()
        ->and($deletedOnlyItem['active_vms_count'])->toBe(0);
});
