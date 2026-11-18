<?php

declare(strict_types=1);

use App\Models\Host;
use App\Models\Image;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $this->admin = User::factory()->create();
    $this->admin->assignRole($adminRole);
});

test('creating an image via model factory attaches to auto_attach hosts and ignores non-auto_attach hosts', function (): void {
    $autoHost1 = Host::factory()->create(['auto_attach_images' => true]);
    $autoHost2 = Host::factory()->create(['auto_attach_images' => true]);
    $manualHost = Host::factory()->create(['auto_attach_images' => false]);

    $image = Image::factory()->create();

    $attachedHostIds = $image->hosts()->pluck('hosts.id')->sort()->values()->all();
    $expectedHostIds = collect([$autoHost1->id, $autoHost2->id])->sort()->values()->all();

    expect($attachedHostIds)->toBe($expectedHostIds)
        ->and(DB::table('host_image')->where('image_id', $image->id)->where('host_id', $manualHost->id)->exists())->toBeFalse();
});

test('creating an image via POST /api/admin/images without host_ids attaches only to auto_attach hosts', function (): void {
    $autoHost = Host::factory()->create(['auto_attach_images' => true]);
    $manualHost = Host::factory()->create(['auto_attach_images' => false]);

    $response = $this->actingAs($this->admin)->postJson('/api/admin/images', [
        'name' => 'ubuntu/noble-auto-test',
        'alias' => 'Test Auto Attach Image',
        'source' => 'https://images.canonical.com',
        'protocol' => 'simplestreams',
        'os_type' => 'linux',
        'enabled' => true,
    ]);

    $response->assertStatus(201);
    $imageId = $response->json('image.id');

    expect($response->json('image.host_ids'))->toBe([$autoHost->id])
        ->and(DB::table('host_image')->where('image_id', $imageId)->where('host_id', $autoHost->id)->exists())->toBeTrue()
        ->and(DB::table('host_image')->where('image_id', $imageId)->where('host_id', $manualHost->id)->exists())->toBeFalse();
});

test('creating an image via POST /api/admin/images with explicit host_ids overrides auto_attach behavior', function (): void {
    $autoHost = Host::factory()->create(['auto_attach_images' => true]);
    $manualHost = Host::factory()->create(['auto_attach_images' => false]);

    $response = $this->actingAs($this->admin)->postJson('/api/admin/images', [
        'name' => 'ubuntu/noble-explicit-test',
        'alias' => 'Test Auto Attach Image',
        'source' => 'https://images.canonical.com',
        'protocol' => 'simplestreams',
        'os_type' => 'linux',
        'enabled' => true,
        'host_ids' => [$manualHost->id],
    ]);

    $response->assertStatus(201);
    $imageId = $response->json('image.id');

    expect($response->json('image.host_ids'))->toBe([$manualHost->id])
        ->and(DB::table('host_image')->where('image_id', $imageId)->where('host_id', $manualHost->id)->exists())->toBeTrue()
        ->and(DB::table('host_image')->where('image_id', $imageId)->where('host_id', $autoHost->id)->exists())->toBeFalse();
});

test('creating an image via POST /api/admin/images with empty host_ids array attaches to no hosts', function (): void {
    $autoHost = Host::factory()->create(['auto_attach_images' => true]);

    $response = $this->actingAs($this->admin)->postJson('/api/admin/images', [
        'name' => 'ubuntu/noble-empty-test',
        'alias' => 'Test Auto Attach Image',
        'source' => 'https://images.canonical.com',
        'protocol' => 'simplestreams',
        'os_type' => 'linux',
        'enabled' => true,
        'host_ids' => [],
    ]);

    $response->assertStatus(201);
    $imageId = $response->json('image.id');

    expect($response->json('image.host_ids'))->toBe([])
        ->and(DB::table('host_image')->where('image_id', $imageId)->exists())->toBeFalse()
        ->and(DB::table('host_image')->where('image_id', $imageId)->where('host_id', $autoHost->id)->exists())->toBeFalse();
});

test('updating an image via PUT /api/admin/images/{id} with host_ids syncs associations as expected', function (): void {
    $host1 = Host::factory()->create(['auto_attach_images' => false]);
    $host2 = Host::factory()->create(['auto_attach_images' => false]);

    $image = Image::factory()->create();
    $image->hosts()->sync([$host1->id]);

    $response = $this->actingAs($this->admin)->putJson("/api/admin/images/{$image->id}", [
        'host_ids' => [$host2->id],
    ]);

    $response->assertStatus(200);
    expect($response->json('image.host_ids'))->toBe([$host2->id])
        ->and(DB::table('host_image')->where('image_id', $image->id)->where('host_id', $host2->id)->exists())->toBeTrue()
        ->and(DB::table('host_image')->where('image_id', $image->id)->where('host_id', $host1->id)->exists())->toBeFalse();

    $responseEmpty = $this->actingAs($this->admin)->putJson("/api/admin/images/{$image->id}", [
        'host_ids' => [],
    ]);

    $responseEmpty->assertStatus(200);
    expect($responseEmpty->json('image.host_ids'))->toBe([])
        ->and(DB::table('host_image')->where('image_id', $image->id)->exists())->toBeFalse();
});
