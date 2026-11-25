<?php

declare(strict_types=1);

test('GET /api/v1/config is publicly accessible without authentication and returns expected structure and types', function (): void {
    $response = $this->getJson('/api/v1/config');

    $response->assertOk()
        ->assertJsonStructure([
            'app_name',
            'app_env',
            'api_base_url',
            'ws_host',
            'ws_port',
            'ws_key',
            'features' => [
                'registration',
                'impersonation',
                'fake_driver',
            ],
        ]);

    /** @var array<string, mixed> $payload */
    $payload = $response->json();

    expect($payload['app_name'])->toBeString()
        ->and($payload['app_env'])->toBeString()
        ->and($payload['api_base_url'])->toBe('/api/v1')
        ->and($payload['ws_host'])->toBeNull()
        ->and($payload['ws_port'])->toBeNull()
        ->and($payload['ws_key'])->toBeString()
        ->and($payload['features'])->toBeArray()
        ->and($payload['features']['registration'])->toBeBool()
        ->and($payload['features']['impersonation'])->toBeBool()
        ->and($payload['features']['fake_driver'])->toBeBool();
});

test('GET /api/v1/config reflects configured runtime values and defaults', function (): void {
    config()->set('app.name', 'Ognivo Cloud');
    config()->set('app.env', 'staging');
    config()->set('reverb.public_host', 'ws.ognivo.test');
    config()->set('reverb.public_port', 6443);
    config()->set('reverb.apps.apps.0.key', 'test-reverb-key');
    config()->set('auth.registration_enabled', true);
    config()->set('auth.impersonation_enabled', false);
    config()->set('app.allow_fake_driver', true);

    $response = $this->getJson('/api/v1/config');

    $response->assertOk()
        ->assertExactJson([
            'app_name' => 'Ognivo Cloud',
            'app_env' => 'staging',
            'api_base_url' => '/api/v1',
            'ws_host' => 'ws.ognivo.test',
            'ws_port' => 6443,
            'ws_key' => 'test-reverb-key',
            'features' => [
                'registration' => true,
                'impersonation' => false,
                'fake_driver' => true,
            ],
        ]);
});

test('GET /api/v1/config returns null for ws_host and ws_port even when internal reverb options are set', function (): void {
    config()->set('reverb.public_host', '   ');
    config()->set('reverb.public_port', null);
    config()->set('broadcasting.public_host', null);
    config()->set('broadcasting.public_port', null);
    config()->set('broadcasting.connections.reverb.options.host', 'reverb');
    config()->set('broadcasting.connections.reverb.options.port', 8080);
    config()->set('reverb.servers.reverb.hostname', 'reverb.ognivo.internal');
    config()->set('app.allow_fake_driver', false);

    $response = $this->getJson('/api/v1/config');

    $response->assertOk()
        ->assertJsonPath('ws_host', null)
        ->assertJsonPath('ws_port', null)
        ->assertJsonPath('features.fake_driver', false);
});
