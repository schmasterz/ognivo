<?php

declare(strict_types=1);

use App\Models\Image;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Cache::flush();
    Http::preventStrayRequests();
    Image::query()->delete();
});

test('test_command_verifies_images_and_returns_success_when_all_valid', function (): void {
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
                'alpine:3.20:amd64:default' => [
                    'aliases' => 'alpine/3.20/default,alpine/3.20',
                    'arch' => 'amd64',
                ],
            ],
        ], 200),
    ]);

    Image::factory()->create([
        'name' => 'debian/12',
        'alias' => 'Debian 12',
        'source' => 'https://images.linuxcontainers.org',
        'protocol' => 'simplestreams',
        'enabled' => true,
    ]);

    Image::factory()->create([
        'name' => 'alpine/3.20',
        'alias' => 'Alpine 3.20',
        'source' => 'https://images.linuxcontainers.org',
        'protocol' => 'simplestreams',
        'enabled' => true,
    ]);

    $this->artisan('images:verify-catalog')
        ->expectsOutputToContain('Verification complete: 2 passed, 0 failed.')
        ->assertExitCode(0);
});

test('test_command_returns_failure_when_an_image_is_invalid', function (): void {
    Http::fake([
        'https://incus.local:8443/1.0/images/aliases/missing-alias' => Http::response([], 404),
    ]);

    Image::factory()->create([
        'name' => 'missing-alias',
        'alias' => 'Missing Alias',
        'source' => 'https://incus.local:8443',
        'protocol' => 'incus',
        'enabled' => true,
    ]);

    $this->artisan('images:verify-catalog')
        ->expectsOutputToContain('Verification complete: 0 passed, 1 failed.')
        ->assertExitCode(1);
});

test('test_command_honors_skip_disabled_and_id_options', function (): void {
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
        'https://incus.local:8443/1.0/images/aliases/broken-disabled-img' => Http::response([], 404),
    ]);

    $disabledInvalidImage = Image::factory()->create([
        'name' => 'broken-disabled-img',
        'alias' => 'Broken Disabled',
        'source' => 'https://incus.local:8443',
        'protocol' => 'incus',
        'enabled' => false,
    ]);

    $enabledValidImage = Image::factory()->create([
        'name' => 'debian/12',
        'alias' => 'Debian 12',
        'source' => 'https://images.linuxcontainers.org',
        'protocol' => 'simplestreams',
        'enabled' => true,
    ]);

    $this->artisan('images:verify-catalog --skip-disabled')
        ->expectsOutputToContain('Verification complete: 1 passed, 0 failed.')
        ->assertExitCode(0);

    $this->artisan("images:verify-catalog --id={$enabledValidImage->id}")
        ->expectsOutputToContain('Verification complete: 1 passed, 0 failed.')
        ->assertExitCode(0);

    $this->artisan("images:verify-catalog --id={$disabledInvalidImage->id}")
        ->expectsOutputToContain('Verification complete: 0 passed, 1 failed.')
        ->assertExitCode(1);
});
