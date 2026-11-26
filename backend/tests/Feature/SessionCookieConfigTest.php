<?php

declare(strict_types=1);

use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;

const COOKIE_CONFIG_TRACKED_ENV = [
    'APP_KEY',
    'APP_DEBUG',
    'APP_URL',
    'ALLOW_FAKE_DRIVER',
    'RUN_SEEDERS',
    'DB_PASSWORD',
    'DB_ROOT_PASSWORD',
    'REVERB_APP_SECRET',
    'REVERB_ALLOWED_ORIGINS',
    'REDIS_PASSWORD',
    'REVERB_APP_KEY',
    'AUDIT_DB_PASSWORD',
    'AUDIT_MAINT_DB_PASSWORD',
    'DB_MIGRATION_PASSWORD',
    'AUDIT_HMAC_KEY',
    'AUDIT_HMAC_KEY_ID',
    'AUDIT_PSEUDONYM_SALT',
    'AUDIT_STRICT_GRANTS',
    'SESSION_SECURE_COOKIE',
    'SESSION_SAME_SITE',
    'SESSION_DOMAIN',
    'SESSION_COOKIE',
    'CONSOLE_ALLOWED_ORIGINS',
];

const COOKIE_CONFIG_TRACKED_KEYS = [
    'app.key',
    'app.debug',
    'app.url',
    'app.allow_fake_driver',
    'app.run_seeders',
    'database.connections.mysql.password',
    'database.connections.mysql.root_password',
    'reverb.apps.apps.0.secret',
    'reverb.apps.apps.0.allowed_origins',
    'database.redis.default.password',
    'reverb.apps.apps.0.key',
    'database.connections.audit.password',
    'database.connections.audit_maintenance.password',
    'database.connections.migration.password',
    'audit.hmac_keys.v1',
    'audit.hmac_key_id',
    'audit.hmac.current_key',
    'audit.hmac.keys.v1',
    'audit.pseudonym_salt',
    'audit.strict_grants',
    'session.secure',
    'session.same_site',
    'session.domain',
    'session.path',
    'session.cookie',
    'session.http_only',
    'sanctum.stateful',
];

/**
 * Find a cookie by name from a TestResponse.
 */
function findConfigTestCookie(TestResponse $response, string $name): ?Cookie
{
    foreach ($response->headers->getCookies() as $cookie) {
        if ($cookie->getName() === $name) {
            return $cookie;
        }
    }

    return null;
}

/**
 * Find the raw Set-Cookie header string for a given cookie name.
 */
function findRawSetCookieHeader(TestResponse $response, string $cookieName): ?string
{
    foreach ($response->headers->all('set-cookie') as $header) {
        if (is_string($header) && str_starts_with($header, $cookieName.'=')) {
            return $header;
        }
    }

    return null;
}

/**
 * Set an environment variable consistently across $_ENV, $_SERVER, and putenv().
 */
function setProdCheckEnv(string $key, string $value): void
{
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv("{$key}={$value}");
}

/**
 * Apply a baseline valid production configuration for app:check-production-config tests.
 */
function applyValidProductionConfig(): void
{
    $validKey = 'base64:'.base64_encode(random_bytes(32));

    config([
        'app.key' => $validKey,
        'app.debug' => false,
        'app.url' => 'https://panel.example.com',
        'app.allow_fake_driver' => false,
        'app.run_seeders' => false,
        'database.connections.mysql.password' => 'super_secure_random_db_pass_1234',
        'database.connections.mysql.root_password' => 'super_secure_random_root_pass_5678',
        'reverb.apps.apps.0.secret' => 'super_secure_reverb_secret_9999',
        'reverb.apps.apps.0.allowed_origins' => ['https://panel.example.com'],
        'database.redis.default.password' => 'super_secure_redis_password_9876',
        'reverb.apps.apps.0.key' => 'valid_reverb_app_key_1234',
        'database.connections.audit.password' => 'super_secure_audit_pass_1234',
        'database.connections.audit_maintenance.password' => 'super_secure_audit_maint_pass_5678',
        'database.connections.migration.password' => 'super_secure_migration_pass_9012',
        'audit.hmac_keys.v1' => 'super_secure_audit_hmac_key_3456',
        'audit.hmac_key_id' => 'v1',
        'audit.hmac.current_key' => 'super_secure_audit_hmac_key_3456',
        'audit.hmac.keys.v1' => 'super_secure_audit_hmac_key_3456',
        'audit.pseudonym_salt' => 'super_secure_audit_salt_7890',
        'audit.strict_grants' => true,
        'session.secure' => true,
        'session.same_site' => 'strict',
        'session.domain' => null,
        'session.cookie' => '__Host-ognivo_session',
        'sanctum.stateful' => ['panel.example.com'],
    ]);

    setProdCheckEnv('APP_KEY', $validKey);
    setProdCheckEnv('APP_DEBUG', 'false');
    setProdCheckEnv('APP_URL', 'https://panel.example.com');
    setProdCheckEnv('ALLOW_FAKE_DRIVER', 'false');
    setProdCheckEnv('RUN_SEEDERS', 'false');
    setProdCheckEnv('DB_PASSWORD', 'super_secure_random_db_pass_1234');
    setProdCheckEnv('DB_ROOT_PASSWORD', 'super_secure_random_root_pass_5678');
    setProdCheckEnv('REVERB_APP_SECRET', 'super_secure_reverb_secret_9999');
    setProdCheckEnv('REVERB_ALLOWED_ORIGINS', 'https://panel.example.com');
    setProdCheckEnv('REDIS_PASSWORD', 'super_secure_redis_password_9876');
    setProdCheckEnv('REVERB_APP_KEY', 'valid_reverb_app_key_1234');
    setProdCheckEnv('AUDIT_DB_PASSWORD', 'super_secure_audit_pass_1234');
    setProdCheckEnv('AUDIT_MAINT_DB_PASSWORD', 'super_secure_audit_maint_pass_5678');
    setProdCheckEnv('DB_MIGRATION_PASSWORD', 'super_secure_migration_pass_9012');
    setProdCheckEnv('AUDIT_HMAC_KEY', 'super_secure_audit_hmac_key_3456');
    setProdCheckEnv('AUDIT_HMAC_KEY_ID', 'v1');
    setProdCheckEnv('AUDIT_PSEUDONYM_SALT', 'super_secure_audit_salt_7890');
    setProdCheckEnv('AUDIT_STRICT_GRANTS', 'true');
    setProdCheckEnv('SESSION_SECURE_COOKIE', 'true');
    setProdCheckEnv('SESSION_SAME_SITE', 'strict');
    setProdCheckEnv('SESSION_DOMAIN', '');
    setProdCheckEnv('SESSION_COOKIE', '__Host-ognivo_session');
    setProdCheckEnv('CONSOLE_ALLOWED_ORIGINS', 'https://panel.example.com');
}

beforeEach(function (): void {
    $this->savedGetenv = [];
    $this->savedEnv = [];
    $this->savedServer = [];
    $this->savedConfig = [];

    foreach (COOKIE_CONFIG_TRACKED_ENV as $key) {
        $this->savedGetenv[$key] = getenv($key);
        if (array_key_exists($key, $_ENV)) {
            $this->savedEnv[$key] = $_ENV[$key];
        }
        if (array_key_exists($key, $_SERVER)) {
            $this->savedServer[$key] = $_SERVER[$key];
        }
    }

    foreach (COOKIE_CONFIG_TRACKED_KEYS as $key) {
        $this->savedConfig[$key] = config($key);
    }
});

afterEach(function (): void {
    foreach (COOKIE_CONFIG_TRACKED_ENV as $key) {
        $orig = $this->savedGetenv[$key] ?? false;
        if ($orig === false) {
            putenv($key);
        } else {
            putenv("{$key}={$orig}");
        }

        if (array_key_exists($key, $this->savedEnv)) {
            $_ENV[$key] = $this->savedEnv[$key];
        } else {
            unset($_ENV[$key]);
        }

        if (array_key_exists($key, $this->savedServer)) {
            $_SERVER[$key] = $this->savedServer[$key];
        } else {
            unset($_SERVER[$key]);
        }
    }

    foreach ($this->savedConfig as $key => $value) {
        config([$key => $value]);
    }
});

test('production cookie config emits __Host-ognivo_session with Secure, HttpOnly, SameSite=Strict, Path=/ and no Domain', function (): void {
    config([
        'session.cookie' => '__Host-ognivo_session',
        'session.secure' => true,
        'session.same_site' => 'strict',
        'session.domain' => null,
        'session.path' => '/',
        'sanctum.stateful' => ['panel.example.com'],
    ]);

    $response = $this->withHeader('Origin', 'https://panel.example.com')
        ->getJson('https://panel.example.com/sanctum/csrf-cookie');

    $response->assertNoContent();

    $sessionCookie = findConfigTestCookie($response, '__Host-ognivo_session');
    expect($sessionCookie)->not->toBeNull();
    expect($sessionCookie?->isSecure())->toBeTrue();
    expect($sessionCookie?->isHttpOnly())->toBeTrue();
    expect(strtolower((string) $sessionCookie?->getSameSite()))->toBe('strict');
    expect($sessionCookie?->getPath())->toBe('/');
    expect($sessionCookie?->getDomain())->toBeNull();

    $rawHeader = findRawSetCookieHeader($response, '__Host-ognivo_session');
    expect($rawHeader)->not->toBeNull();
    expect(strtolower((string) $rawHeader))->toContain('secure')
        ->toContain('httponly')
        ->toContain('samesite=strict')
        ->toContain('path=/')
        ->not->toContain('domain=');
});

test('non-production default config emits ognivo_session with HttpOnly and SameSite=Strict and without Secure', function (): void {
    expect(config('session.cookie'))->toBe('ognivo_session');
    expect(config('session.http_only'))->toBeTrue();
    expect(config('session.same_site'))->toBe('strict');
    expect(config('session.absolute_lifetime'))->toBe(1440);
    expect(config('session.lifetime'))->toBe(120);

    $response = $this->withHeader('Origin', 'http://localhost')
        ->getJson('http://localhost/sanctum/csrf-cookie');

    $response->assertNoContent();

    $sessionCookie = findConfigTestCookie($response, 'ognivo_session');
    expect($sessionCookie)->not->toBeNull();
    expect($sessionCookie?->isHttpOnly())->toBeTrue();
    expect(strtolower((string) $sessionCookie?->getSameSite()))->toBe('strict');
    expect($sessionCookie?->isSecure())->toBeFalse();

    $rawHeader = findRawSetCookieHeader($response, 'ognivo_session');
    expect($rawHeader)->not->toBeNull();
    expect(strtolower((string) $rawHeader))->toContain('httponly')
        ->toContain('samesite=strict')
        ->not->toContain('; secure');
});

test('GET /sanctum/csrf-cookie sets XSRF-TOKEN without HttpOnly, with SameSite=Strict, Path=/, and Secure when session.secure is true', function (): void {
    config([
        'session.secure' => false,
        'session.same_site' => 'strict',
        'session.path' => '/',
    ]);

    $httpResponse = $this->withHeader('Origin', 'http://localhost')
        ->getJson('http://localhost/sanctum/csrf-cookie');
    $httpResponse->assertNoContent();

    $httpXsrf = findConfigTestCookie($httpResponse, 'XSRF-TOKEN');
    expect($httpXsrf)->not->toBeNull();
    expect($httpXsrf?->isHttpOnly())->toBeFalse();
    expect(strtolower((string) $httpXsrf?->getSameSite()))->toBe('strict');
    expect($httpXsrf?->getPath())->toBe('/');
    expect($httpXsrf?->isSecure())->toBeFalse();

    config([
        'session.secure' => true,
        'sanctum.stateful' => ['panel.example.com'],
    ]);

    $httpsResponse = $this->withHeader('Origin', 'https://panel.example.com')
        ->getJson('https://panel.example.com/sanctum/csrf-cookie');
    $httpsResponse->assertNoContent();

    $httpsXsrf = findConfigTestCookie($httpsResponse, 'XSRF-TOKEN');
    expect($httpsXsrf)->not->toBeNull();
    expect($httpsXsrf?->isHttpOnly())->toBeFalse();
    expect(strtolower((string) $httpsXsrf?->getSameSite()))->toBe('strict');
    expect($httpsXsrf?->getPath())->toBe('/');
    expect($httpsXsrf?->isSecure())->toBeTrue();
});

test('app:check-production-config passes with valid cookie/session/CORS settings and fails on insecure values', function (callable $mutator, string $expectedMessage): void {
    applyValidProductionConfig();

    $this->artisan('app:check-production-config')
        ->assertExitCode(0);

    $mutator();

    $this->artisan('app:check-production-config')
        ->expectsOutputToContain($expectedMessage)
        ->assertExitCode(1);
})->with([
    'insecure session.secure false' => [
        static function (): void {
            config(['session.secure' => false]);
            setProdCheckEnv('SESSION_SECURE_COOKIE', 'false');
        },
        'SESSION_SECURE_COOKIE',
    ],
    'insecure session.same_site lax' => [
        static function (): void {
            config(['session.same_site' => 'lax']);
            setProdCheckEnv('SESSION_SAME_SITE', 'lax');
        },
        "SESSION_SAME_SITE must be 'strict'",
    ],
    'non-empty session.domain' => [
        static function (): void {
            config(['session.domain' => '.example.com']);
            setProdCheckEnv('SESSION_DOMAIN', '.example.com');
        },
        'SESSION_DOMAIN must be empty',
    ],
    'missing __Host- cookie prefix' => [
        static function (): void {
            config(['session.cookie' => 'ognivo_session']);
            setProdCheckEnv('SESSION_COOKIE', 'ognivo_session');
        },
        "SESSION_COOKIE must start with '__Host-'",
    ],
    'empty sanctum.stateful' => [
        static function (): void {
            config(['sanctum.stateful' => []]);
        },
        'SANCTUM_STATEFUL_DOMAINS is empty',
    ],
    'empty CONSOLE_ALLOWED_ORIGINS' => [
        static function (): void {
            setProdCheckEnv('CONSOLE_ALLOWED_ORIGINS', '');
        },
        'CONSOLE_ALLOWED_ORIGINS is empty',
    ],
    'wildcard CONSOLE_ALLOWED_ORIGINS' => [
        static function (): void {
            setProdCheckEnv('CONSOLE_ALLOWED_ORIGINS', '*');
        },
        "CONSOLE_ALLOWED_ORIGINS contains wildcard '*'",
    ],
]);
