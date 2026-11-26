<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

/**
 * Find a cookie by name from a TestResponse.
 */
function findBroadcastCookie(TestResponse $response, string $name): ?Cookie
{
    foreach ($response->headers->getCookies() as $cookie) {
        if ($cookie->getName() === $name) {
            return $cookie;
        }
    }

    return null;
}

/**
 * Dispatch an isolated JSON HTTP request with explicit cookies and headers.
 *
 * @param  array<string, string>  $cookies
 * @param  array<string, string>  $headers
 * @param  array<string, mixed>  $data
 */
function dispatchBroadcastJson(
    TestCase $test,
    string $method,
    string $uri,
    array $cookies = [],
    array $headers = [],
    array $data = [],
): TestResponse {
    app('auth')->forgetGuards();
    $sessionStore = app('session')->driver();
    $sessionStore->flush();
    $sessionStore->setId(null);

    $content = $method === 'GET' ? '' : json_encode($data, JSON_THROW_ON_ERROR);
    $server = [
        'HTTP_ACCEPT' => 'application/json',
    ];

    if ($method !== 'GET') {
        $server['CONTENT_TYPE'] = 'application/json';
        $server['CONTENT_LENGTH'] = (string) mb_strlen($content, '8bit');
    }

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return $test->call($method, $uri, [], $cookies, [], $server, $content === '' ? null : $content);
}

/**
 * Authenticate a user via stateful cookie login and return session + XSRF cookies.
 *
 * @return array{session: string, xsrf: string}
 */
function loginForBroadcastSession(TestCase $test, User $user, string $password = 'SecretPass123!'): array
{
    $csrfResponse = dispatchBroadcastJson($test, 'GET', '/sanctum/csrf-cookie', [], ['Origin' => 'http://localhost']);
    $csrfResponse->assertNoContent();

    $xsrf = (string) $csrfResponse->getCookie('XSRF-TOKEN', decrypt: false)?->getValue();
    $sessionCookieName = (string) config('session.cookie');
    $preSession = findBroadcastCookie($csrfResponse, $sessionCookieName)?->getValue();

    $cookies = ['XSRF-TOKEN' => $xsrf];
    if ($preSession !== null) {
        $cookies[$sessionCookieName] = $preSession;
    }

    $loginResponse = dispatchBroadcastJson(
        $test,
        'POST',
        '/api/v1/login',
        $cookies,
        [
            'Origin' => 'http://localhost',
            'X-XSRF-TOKEN' => $xsrf,
        ],
        [
            'email' => $user->email,
            'password' => $password,
        ],
    );

    $loginResponse->assertOk();

    $session = (string) (findBroadcastCookie($loginResponse, $sessionCookieName)?->getValue() ?? $preSession ?? '');
    $newXsrf = findBroadcastCookie($loginResponse, 'XSRF-TOKEN')?->getValue();

    return [
        'session' => $session,
        'xsrf' => $newXsrf ?? $xsrf,
    ];
}

/**
 * Evaluate config/reverb.php under temporary environment overrides and restore env afterwards.
 *
 * @param  array<string, string|null>  $envOverrides
 * @return array<string, mixed>
 */
function loadReverbConfigWithEnv(array $envOverrides): array
{
    $originalGetenv = [];
    $originalEnv = [];
    $originalServer = [];

    foreach (array_keys($envOverrides) as $key) {
        $originalGetenv[$key] = getenv($key);
        if (array_key_exists($key, $_ENV)) {
            $originalEnv[$key] = $_ENV[$key];
        }
        if (array_key_exists($key, $_SERVER)) {
            $originalServer[$key] = $_SERVER[$key];
        }
    }

    try {
        foreach ($envOverrides as $key => $value) {
            if ($value === null) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }

        /** @var array<string, mixed> $loaded */
        $loaded = require config_path('reverb.php');

        return $loaded;
    } finally {
        foreach (array_keys($envOverrides) as $key) {
            if ($originalGetenv[$key] === false) {
                putenv($key);
            } else {
                putenv("{$key}={$originalGetenv[$key]}");
            }

            if (array_key_exists($key, $originalEnv)) {
                $_ENV[$key] = $originalEnv[$key];
            } else {
                unset($_ENV[$key]);
            }

            if (array_key_exists($key, $originalServer)) {
                $_SERVER[$key] = $originalServer[$key];
            } else {
                unset($_SERVER[$key]);
            }
        }
    }
}

afterEach(function (): void {
    app()->offsetSet('env', 'testing');
});

test('broadcasting auth authorizes channel owner with session and X-XSRF-TOKEN and rejects other users, missing auth, and missing CSRF', function (): void {
    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

    $owner = User::factory()->withPassword('SecretPass123!')->create([
        'email' => 'broadcast-owner@example.com',
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $owner->assignRole('customer');

    $otherUser = User::factory()->withPassword('SecretPass123!')->create([
        'email' => 'broadcast-other@example.com',
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $otherUser->assignRole('customer');

    $previousEnv = app()->environment();
    $previousBroadcastDefault = config('broadcasting.default');
    $previousReverbKey = config('broadcasting.connections.reverb.key');
    $previousReverbSecret = config('broadcasting.connections.reverb.secret');
    $previousReverbAppId = config('broadcasting.connections.reverb.app_id');

    app()->offsetSet('env', 'local');
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'reverb-test-key',
        'broadcasting.connections.reverb.secret' => 'reverb-test-secret',
        'broadcasting.connections.reverb.app_id' => '1001',
    ]);
    Broadcast::purge();
    require base_path('routes/channels.php');

    try {
        $auth = loginForBroadcastSession($this, $owner);
        $sessionCookieName = (string) config('session.cookie');

        // Owner with valid session + X-XSRF-TOKEN is authorized
        $ownerResponse = dispatchBroadcastJson(
            $this,
            'POST',
            '/api/v1/broadcasting/auth',
            [
                $sessionCookieName => $auth['session'],
                'XSRF-TOKEN' => $auth['xsrf'],
            ],
            [
                'Origin' => 'http://localhost',
                'X-XSRF-TOKEN' => $auth['xsrf'],
            ],
            [
                'channel_name' => 'private-users.'.$owner->id,
                'socket_id' => '1234.5678',
            ],
        );
        $ownerResponse->assertOk();

        // Owner requesting another user's private channel is rejected with 403
        $otherChannelResponse = dispatchBroadcastJson(
            $this,
            'POST',
            '/api/v1/broadcasting/auth',
            [
                $sessionCookieName => $auth['session'],
                'XSRF-TOKEN' => $auth['xsrf'],
            ],
            [
                'Origin' => 'http://localhost',
                'X-XSRF-TOKEN' => $auth['xsrf'],
            ],
            [
                'channel_name' => 'private-users.'.$otherUser->id,
                'socket_id' => '1234.5678',
            ],
        );
        $otherChannelResponse->assertStatus(403);

        // Missing X-XSRF-TOKEN on stateful broadcasting auth is rejected with 419
        $missingCsrfResponse = dispatchBroadcastJson(
            $this,
            'POST',
            '/api/v1/broadcasting/auth',
            [
                $sessionCookieName => $auth['session'],
            ],
            [
                'Origin' => 'http://localhost',
            ],
            [
                'channel_name' => 'private-users.'.$owner->id,
                'socket_id' => '1234.5678',
            ],
        );
        $missingCsrfResponse->assertStatus(419);

        // Unauthenticated request (even with valid CSRF cookie/header) is rejected with 401/403
        $unauthCsrf = dispatchBroadcastJson($this, 'GET', '/sanctum/csrf-cookie', [], ['Origin' => 'http://localhost']);
        $unauthXsrf = (string) $unauthCsrf->getCookie('XSRF-TOKEN', decrypt: false)?->getValue();
        $unauthSession = findBroadcastCookie($unauthCsrf, $sessionCookieName)?->getValue();

        $unauthCookies = ['XSRF-TOKEN' => $unauthXsrf];
        if ($unauthSession !== null) {
            $unauthCookies[$sessionCookieName] = $unauthSession;
        }

        $unauthenticatedResponse = dispatchBroadcastJson(
            $this,
            'POST',
            '/api/v1/broadcasting/auth',
            $unauthCookies,
            [
                'Origin' => 'http://localhost',
                'X-XSRF-TOKEN' => $unauthXsrf,
            ],
            [
                'channel_name' => 'private-users.'.$owner->id,
                'socket_id' => '1234.5678',
            ],
        );
        expect($unauthenticatedResponse->status())->toBeIn([401, 403]);
    } finally {
        app()->offsetSet('env', is_string($previousEnv) ? $previousEnv : 'testing');
        config([
            'broadcasting.default' => $previousBroadcastDefault,
            'broadcasting.connections.reverb.key' => $previousReverbKey,
            'broadcasting.connections.reverb.secret' => $previousReverbSecret,
            'broadcasting.connections.reverb.app_id' => $previousReverbAppId,
        ]);
        Broadcast::purge();
    }
});

test('reverb allowed_origins extracts configured hosts and excludes wildcards in production-like config', function (): void {
    $reverbConfig = loadReverbConfigWithEnv([
        'REVERB_ALLOWED_ORIGINS' => 'https://panel.example.com, https://console.example.com:8443',
        'APP_URL' => 'https://panel.example.com',
    ]);

    /** @var array<int, string> $allowedOrigins */
    $allowedOrigins = $reverbConfig['apps']['apps'][0]['allowed_origins'] ?? [];

    expect($allowedOrigins)->toBe(['panel.example.com', 'console.example.com'])
        ->not->toContain('*');

    $fallbackConfig = loadReverbConfigWithEnv([
        'REVERB_ALLOWED_ORIGINS' => null,
        'APP_URL' => 'https://app.example.org',
    ]);

    /** @var array<int, string> $fallbackOrigins */
    $fallbackOrigins = $fallbackConfig['apps']['apps'][0]['allowed_origins'] ?? [];

    expect($fallbackOrigins)->toBe(['app.example.org'])
        ->not->toContain('*');
});
