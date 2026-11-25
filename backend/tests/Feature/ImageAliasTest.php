<?php

declare(strict_types=1);

use App\Models\Image;
use App\Models\User;
use App\Models\VirtualMachine;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $customerRole = Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole($adminRole);

    $this->customer = User::factory()->create();
    $this->customer->assignRole($customerRole);
});

test('test_admin_can_create_image_with_alias_and_view_it_in_catalog', function (): void {
    $response = $this->actingAs($this->admin)->postJson('/api/v1/admin/images', [
        'name' => 'custom/ubuntu-noble-alias-test',
        'alias' => 'Ubuntu 24.04 Noble',
        'source' => 'https://images.canonical.com',
        'protocol' => 'simplestreams',
        'os_type' => 'linux',
        'enabled' => true,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('image.alias', 'Ubuntu 24.04 Noble');

    $catalogResponse = $this->actingAs($this->admin)->getJson('/api/v1/admin/images');

    $catalogResponse->assertStatus(200)
        ->assertJsonPath('data.0.alias', 'Ubuntu 24.04 Noble');
});

test('test_create_image_requires_alias', function (): void {
    $response = $this->actingAs($this->admin)->postJson('/api/v1/admin/images', [
        'name' => 'custom/ubuntu-missing-alias',
        'source' => 'https://images.canonical.com',
        'protocol' => 'simplestreams',
        'os_type' => 'linux',
        'enabled' => true,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['alias']);
});

test('test_admin_can_update_image_alias', function (): void {
    $image = Image::factory()->create([
        'name' => 'custom/debian-update-alias',
        'alias' => 'Debian Old Alias',
    ]);

    $response = $this->actingAs($this->admin)->putJson("/api/v1/admin/images/{$image->id}", [
        'alias' => 'Debian 12 Updated',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('image.alias', 'Debian 12 Updated');
});

test('test_admin_can_search_images_by_alias', function (): void {
    Image::query()->delete();

    Image::factory()->create([
        'name' => 'os/img-one',
        'alias' => 'Debian 12 Bookworm',
        'source' => 'https://images.example.org',
    ]);

    Image::factory()->create([
        'name' => 'os/img-two',
        'alias' => 'Alpine Linux 3.20',
        'source' => 'https://images.example.org',
    ]);

    $response = $this->actingAs($this->admin)->getJson('/api/v1/admin/images?search=Debian');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.alias', 'Debian 12 Bookworm');
});

test('test_vm_resource_resolves_image_alias', function (): void {
    Image::factory()->create([
        'name' => 'custom/debian',
        'alias' => 'Debian 12',
    ]);

    VirtualMachine::factory()->create([
        'user_id' => $this->customer->id,
        'os_image' => 'custom/debian',
    ]);

    $response = $this->actingAs($this->customer)->getJson('/api/v1/vms');

    $response->assertStatus(200)
        ->assertJsonPath('data.0.os_image_alias', 'Debian 12');
});
