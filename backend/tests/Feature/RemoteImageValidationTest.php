<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Hypervisor\RemoteImageValidatorService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Cache::flush();
    Http::preventStrayRequests();

    $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $customerRole = Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole($adminRole);

    $this->customer = User::factory()->create();
    $this->customer->assignRole($customerRole);
});

test('test_admin_can_validate_valid_simplestreams_image', function (): void {
    Http::fake([
        'https://images.linuxcontainers.org/streams/v1/index.json' => Http::response([
            'index' => [
                'images' => [
                    'datatype' => 'image-downloads',
                    'path' => 'streams/v1/images.json',
                ],
            ],
        ], 200),
        'https://images.linuxcontainers.org/streams/v1/images.json' => Http::response([
            'products' => [
                'debian:bookworm:amd64:default' => [
                    'aliases' => 'debian/12/default,debian/12',
                    'arch' => 'amd64',
                ],
                'debian:bookworm:arm64:default' => [
                    'aliases' => 'debian/12/default,debian/12',
                    'arch' => 'arm64',
                ],
            ],
        ], 200),
    ]);

    $response = $this->actingAs($this->admin)->postJson('/api/v1/admin/images/validate', [
        'source' => 'https://images.linuxcontainers.org',
        'name' => 'images:debian/12',
        'protocol' => 'simplestreams',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('valid', true)
        ->assertJsonPath('matched_alias', 'debian/12');

    expect($response->json('architectures'))->toContain('amd64', 'arm64');
});

test('test_validate_fails_when_alias_not_found_on_simplestreams_remote', function (): void {
    Http::fake([
        'https://images.linuxcontainers.org/streams/v1/index.json' => Http::response([
            'index' => [
                'images' => [
                    'datatype' => 'image-downloads',
                    'path' => 'streams/v1/images.json',
                ],
            ],
        ], 200),
        'https://images.linuxcontainers.org/streams/v1/images.json' => Http::response([
            'products' => [
                'debian:bookworm:amd64:default' => [
                    'aliases' => 'debian/12/default,debian/12',
                    'arch' => 'amd64',
                ],
            ],
        ], 200),
    ]);

    $response = $this->actingAs($this->admin)->postJson('/api/v1/admin/images/validate', [
        'source' => 'https://images.linuxcontainers.org',
        'name' => 'debian/99',
        'protocol' => 'simplestreams',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('valid', false);

    expect((string) $response->json('error'))->toContain("Image alias 'debian/99' was not found");
});

test('test_validate_incus_protocol_image', function (): void {
    Http::fake([
        'https://incus.local:8443/1.0/images/aliases/my-img' => Http::response([
            'type' => 'sync',
            'status' => 'Success',
            'metadata' => ['target' => 'abc123fingerprint'],
        ], 200),
    ]);

    $response = $this->actingAs($this->admin)->postJson('/api/v1/admin/images/validate', [
        'source' => 'https://incus.local:8443',
        'name' => 'my-img',
        'protocol' => 'incus',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('valid', true)
        ->assertJsonPath('metadata.target', 'abc123fingerprint');
});

test('test_create_image_enforces_remote_validation_when_enabled', function (): void {
    config(['hypervisor.validate_remote_images' => true]);

    Http::fake([
        'https://images.linuxcontainers.org/streams/v1/index.json' => Http::response([
            'index' => [
                'images' => [
                    'datatype' => 'image-downloads',
                    'path' => 'streams/v1/images.json',
                ],
            ],
        ], 200),
        'https://images.linuxcontainers.org/streams/v1/images.json' => Http::response([
            'products' => [],
        ], 200),
    ]);

    $response = $this->actingAs($this->admin)->postJson('/api/v1/admin/images', [
        'name' => 'debian/99',
        'alias' => 'Debian 99',
        'source' => 'https://images.linuxcontainers.org',
        'protocol' => 'simplestreams',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

test('test_create_image_bypasses_remote_validation_when_skip_flag_is_true', function (): void {
    config(['hypervisor.validate_remote_images' => true]);

    Http::fake([
        'https://images.linuxcontainers.org/streams/v1/index.json' => Http::response([
            'index' => [
                'images' => [
                    'datatype' => 'image-downloads',
                    'path' => 'streams/v1/images.json',
                ],
            ],
        ], 200),
        'https://images.linuxcontainers.org/streams/v1/images.json' => Http::response([
            'products' => [],
        ], 200),
    ]);

    $response = $this->actingAs($this->admin)->postJson('/api/v1/admin/images', [
        'name' => 'debian/offline',
        'alias' => 'Debian Offline',
        'source' => 'https://images.linuxcontainers.org',
        'protocol' => 'simplestreams',
        'skip_remote_validation' => true,
    ]);

    $response->assertStatus(201);
    Http::assertNothingSent();
});

test('test_customer_cannot_access_remote_image_validation_endpoint', function (): void {
    $response = $this->actingAs($this->customer)->postJson('/api/v1/admin/images/validate', [
        'source' => 'https://images.linuxcontainers.org',
        'name' => 'debian/12',
        'protocol' => 'simplestreams',
    ]);

    $response->assertStatus(403);
    Http::assertNothingSent();
});

test('test_remote_image_validator_service_handles_unsupported_protocol', function (): void {
    $validator = app(RemoteImageValidatorService::class);
    $result = $validator->validate('https://images.linuxcontainers.org', 'debian/12', 'ftp');

    expect($result->isValid)->toBeFalse()
        ->and((string) $result->error)->toContain("Unsupported image protocol 'ftp'");
});
