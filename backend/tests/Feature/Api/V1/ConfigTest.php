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
            ],
        ]);

    /** @var array<string, mixed> $payload */
    $payload = $response->json();

    expect($payload['app_name'])->toBeString()
        ->and($payload['app_env'])->toBeString()
        ->and($payload['api_base_url'])->toBe('/api/v1')
        ->and($payload['ws_host'])->toBeString()
        ->and($payload['ws_port'])->toBeInt()
        ->and($payload['ws_key'])->toBeString()
        ->and($payload['features'])->toBeArray()
        ->and($payload['features']['registration'])->toBeBool()
        ->and($payload['features']['impersonation'])->toBeBool();
});

test('GET /api/v1/config reflects configured runtime values and defaults', function (): void {
    config()->set('app.name', 'Ognivo Cloud');
    config()->set('app.env', 'staging');
    config()->set('websockets.host', 'ws.ognivo.test');
    config()->set('websockets.port', '6443');
    config()->set('reverb.apps.apps.0.key', 'test-reverb-key');
    config()->set('auth.registration_enabled', true);
    config()->set('auth.impersonation_enabled', false);

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
            ],
        ]);
});
