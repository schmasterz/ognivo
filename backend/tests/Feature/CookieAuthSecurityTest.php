<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

/**
 * Create an active customer user with a known password.
 */
function createActiveCustomer(string $password = 'SecretPass123!', string $email = 'customer@example.com'): User
{
    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

    $user = User::factory()->withPassword($password)->create([
        'email' => $email,
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $user->assignRole('customer');

    return $user;
}

/**
 * Find a cookie by name from a TestResponse without failing if absent.
 */
function findResponseCookie(TestResponse $response, string $name): ?Cookie
{
    foreach ($response->headers->getCookies() as $cookie) {
        if ($cookie->getName() === $name) {
            return $cookie;
        }
    }

    return null;
}

/**
 * Dispatch an isolated JSON HTTP request with explicit cookies and headers without mutating TestCase state.
 *
 * @param  array<string, string>  $cookies
 * @param  array<string, string>  $headers
 * @param  array<string, mixed>  $data
 */
function dispatchJsonRequest(
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
 * Issue GET /sanctum/csrf-cookie from a stateful origin and return raw cookie + XSRF header values.
 *
 * @return array{xsrf: string, session: string|null, decryptedSessionId: string|null}
 */
function fetchStatefulCsrfContext(TestCase $test, string $origin = 'http://localhost'): array
{
    $response = dispatchJsonRequest($test, 'GET', '/sanctum/csrf-cookie', [], ['Origin' => $origin]);

    $response->assertNoContent();

    $xsrfCookie = $response->getCookie('XSRF-TOKEN', decrypt: false);
    expect($xsrfCookie)->not->toBeNull();

    $sessionCookieName = (string) config('session.cookie');
    $rawSessionCookie = findResponseCookie($response, $sessionCookieName);
    $decryptedSessionCookie = $rawSessionCookie !== null ? $response->getCookie($sessionCookieName) : null;

    return [
        'xsrf' => (string) $xsrfCookie?->getValue(),
        'session' => $rawSessionCookie?->getValue(),
        'decryptedSessionId' => $decryptedSessionCookie?->getValue(),
    ];
}

/**
 * Perform a full stateful browser login and return the active session cookie + XSRF token.
 *
 * @return array{xsrf: string, session: string, decryptedSessionId: string, response: TestResponse}
 */
function performStatefulLogin(
    TestCase $test,
    string $email,
    string $password,
    string $origin = 'http://localhost',
): array {
    $csrf = fetchStatefulCsrfContext($test, $origin);

    $cookies = ['XSRF-TOKEN' => $csrf['xsrf']];
    if ($csrf['session'] !== null) {
        $cookies[(string) config('session.cookie')] = $csrf['session'];
    }

    $loginResponse = dispatchJsonRequest(
        $test,
        'POST',
        '/api/v1/login',
        $cookies,
        [
            'Origin' => $origin,
            'X-XSRF-TOKEN' => $csrf['xsrf'],
        ],
        [
            'email' => $email,
            'password' => $password,
        ],
    );

    $sessionCookieName = (string) config('session.cookie');
    $rawSession = findResponseCookie($loginResponse, $sessionCookieName);
    $decryptedSession = $rawSession !== null ? $loginResponse->getCookie($sessionCookieName) : null;
    $rawXsrf = findResponseCookie($loginResponse, 'XSRF-TOKEN');

    return [
        'xsrf' => $rawXsrf !== null ? (string) $rawXsrf->getValue() : $csrf['xsrf'],
        'session' => (string) ($rawSession?->getValue() ?? $csrf['session'] ?? ''),
        'decryptedSessionId' => $decryptedSession?->getValue() ?? $csrf['decryptedSessionId'],
        'response' => $loginResponse,
    ];
}

/**
 * Send a stateful JSON request with explicit session and optional XSRF cookies/headers.
 *
 * @param  array<string, mixed>  $data
 * @param  array<string, string>  $extraHeaders
 */
function sendStatefulRequest(
    TestCase $test,
    string $method,
    string $uri,
    string $sessionCookieValue,
    ?string $xsrfToken = null,
    array $data = [],
    string $origin = 'http://localhost',
    array $extraHeaders = [],
): TestResponse {
    $sessionCookieName = (string) config('session.cookie');
    $cookies = [$sessionCookieName => $sessionCookieValue];
    $headers = array_merge(['Origin' => $origin], $extraHeaders);

    if ($xsrfToken !== null) {
        $cookies['XSRF-TOKEN'] = $xsrfToken;
        $headers['X-XSRF-TOKEN'] = $xsrfToken;
    }

    return dispatchJsonRequest($test, $method, $uri, $cookies, $headers, $data);
}

/**
 * Execute a callback with APP_ENV temporarily switched away from 'testing' so Laravel's
 * PreventRequestForgery middleware enforces real CSRF validation.
 */
function withRealCsrfEnforcement(callable $callback): void
{
    $previousEnv = app()->environment();
    app()->offsetSet('env', 'local');

    try {
        $callback();
    } finally {
        app()->offsetSet('env', is_string($previousEnv) ? $previousEnv : 'testing');
    }
}

afterEach(function (): void {
    Carbon::setTestNow();
    app()->offsetSet('env', 'testing');
});

test('stateful login sets an HttpOnly SameSite=Strict session cookie and omits token from JSON body', function (): void {
    $user = createActiveCustomer('SecretPass123!', 'login-cookie@example.com');

    $login = performStatefulLogin($this, $user->email, 'SecretPass123!');

    $login['response']->assertOk()
        ->assertJsonPath('user.email', $user->email)
        ->assertJsonMissingPath('token');

    expect($login['response']->json())->not->toHaveKey('token');

    $sessionCookieName = (string) config('session.cookie');
    $cookie = findResponseCookie($login['response'], $sessionCookieName);

    expect($cookie)->not->toBeNull();
    expect($cookie?->isHttpOnly())->toBeTrue();
    expect(strtolower((string) $cookie?->getSameSite()))->toBe('strict');
});

test('stateful mutating requests require X-XSRF-TOKEN when CSRF enforcement is active', function (): void {
    $user = createActiveCustomer('SecretPass123!', 'csrf-enforce@example.com');

    withRealCsrfEnforcement(function () use ($user): void {
        $login = performStatefulLogin($this, $user->email, 'SecretPass123!');
        $login['response']->assertOk();

        $putWithoutCsrf = sendStatefulRequest(
            $this,
            'PUT',
            '/api/v1/me',
            $login['session'],
            null,
            [
                'name' => 'No Csrf Name',
                'email' => $user->email,
            ],
        );
        $putWithoutCsrf->assertStatus(419);

        $logoutWithoutCsrf = sendStatefulRequest(
            $this,
            'POST',
            '/api/v1/logout',
            $login['session'],
            null,
        );
        $logoutWithoutCsrf->assertStatus(419);

        $putWithCsrf = sendStatefulRequest(
            $this,
            'PUT',
            '/api/v1/me',
            $login['session'],
            $login['xsrf'],
            [
                'name' => 'Updated Csrf Name',
                'email' => $user->email,
            ],
        );
        $putWithCsrf->assertOk();

        $logoutWithCsrf = sendStatefulRequest(
            $this,
            'POST',
            '/api/v1/logout',
            $login['session'],
            $login['xsrf'],
        );
        $logoutWithCsrf->assertOk();
    });
});

test('requests from an untrusted origin are not treated as stateful and remain unauthenticated', function (): void {
    $user = createActiveCustomer('SecretPass123!', 'evil-origin@example.com');

    $evilLogin = $this->withHeaders([
        'Origin' => 'https://evil.example',
    ])->postJson('/api/v1/login', [
        'email' => $user->email,
        'password' => 'SecretPass123!',
    ]);

    expect($evilLogin->status())->toBeIn([401, 403]);
    expect(Auth::guard('web')->check())->toBeFalse();

    $validLogin = performStatefulLogin($this, $user->email, 'SecretPass123!');
    $validLogin['response']->assertOk();

    $evilLogout = sendStatefulRequest(
        $this,
        'POST',
        '/api/v1/logout',
        $validLogin['session'],
        $validLogin['xsrf'],
        [],
        'https://evil.example',
    );

    expect($evilLogout->status())->toBeIn([401, 403]);
    expect(Auth::guard('web')->check())->toBeFalse();

    $stillValid = sendStatefulRequest(
        $this,
        'GET',
        '/api/v1/me',
        $validLogin['session'],
        $validLogin['xsrf'],
    );
    $stillValid->assertOk();
});

test('replaying a session cookie after logout returns 401', function (): void {
    $user = createActiveCustomer('SecretPass123!', 'replay@example.com');
    $login = performStatefulLogin($this, $user->email, 'SecretPass123!');
    $login['response']->assertOk();

    sendStatefulRequest(
        $this,
        'GET',
        '/api/v1/me',
        $login['session'],
        $login['xsrf'],
    )->assertOk();

    sendStatefulRequest(
        $this,
        'POST',
        '/api/v1/logout',
        $login['session'],
        $login['xsrf'],
    )->assertOk();

    sendStatefulRequest(
        $this,
        'GET',
        '/api/v1/me',
        $login['session'],
        $login['xsrf'],
    )->assertUnauthorized();
});

test('session ID rotates on login to prevent session fixation', function (): void {
    $user = createActiveCustomer('SecretPass123!', 'fixation@example.com');

    $preLogin = fetchStatefulCsrfContext($this);
    expect($preLogin['session'])->not->toBeNull();
    expect($preLogin['decryptedSessionId'])->not->toBeNull();

    $cookies = [
        'XSRF-TOKEN' => $preLogin['xsrf'],
        (string) config('session.cookie') => (string) $preLogin['session'],
    ];

    $loginResponse = dispatchJsonRequest(
        $this,
        'POST',
        '/api/v1/login',
        $cookies,
        [
            'Origin' => 'http://localhost',
            'X-XSRF-TOKEN' => $preLogin['xsrf'],
        ],
        [
            'email' => $user->email,
            'password' => 'SecretPass123!',
        ],
    );

    $loginResponse->assertOk();

    $sessionCookieName = (string) config('session.cookie');
    $postLoginDecryptedId = $loginResponse->getCookie($sessionCookieName)?->getValue();

    expect($postLoginDecryptedId)->toBeString()->not->toBeEmpty();
    expect($postLoginDecryptedId)->not->toBe($preLogin['decryptedSessionId']);

    sendStatefulRequest(
        $this,
        'GET',
        '/api/v1/me',
        (string) $preLogin['session'],
        $preLogin['xsrf'],
    )->assertUnauthorized();
});

test('bearer personal access tokens are rejected while Sanctum::actingAs still authenticates', function (): void {
    $user = createActiveCustomer('SecretPass123!', 'bearer-reject@example.com');
    $plainTextToken = $user->createToken('legacy-test-token')->plainTextToken;

    $this->withHeader('Authorization', 'Bearer '.$plainTextToken)
        ->getJson('/api/v1/me')
        ->assertUnauthorized();

    $this->withHeaders([
        'Origin' => 'http://localhost',
        'Authorization' => 'Bearer '.$plainTextToken,
    ])->getJson('/api/v1/me')
        ->assertUnauthorized();

    $this->flushHeaders();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('user.email', $user->email);
});

test('sixth failed login attempt for same email and IP within a minute returns 429 with Retry-After', function (): void {
    $email = 'bruteforce@example.com';
    createActiveCustomer('SecretPass123!', $email);
    RateLimiter::clear('auth-login:'.strtolower($email).'|127.0.0.1');

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $result = performStatefulLogin($this, $email, 'WrongPassword999!');
        expect($result['response']->status())->toBe(422);
    }

    $sixth = performStatefulLogin($this, $email, 'WrongPassword999!');
    $sixth['response']->assertStatus(429)
        ->assertHeader('Retry-After');

    expect((int) $sixth['response']->headers->get('Retry-After'))->toBeGreaterThan(0);
});

test('suspended user with an active session receives 403 on active-protected routes', function (): void {
    $user = createActiveCustomer('SecretPass123!', 'suspended-live@example.com');
    $login = performStatefulLogin($this, $user->email, 'SecretPass123!');
    $login['response']->assertOk();

    sendStatefulRequest(
        $this,
        'GET',
        '/api/v1/vms',
        $login['session'],
        $login['xsrf'],
    )->assertOk();

    $user->update(['status' => 'suspended']);

    $suspendedResponse = sendStatefulRequest(
        $this,
        'GET',
        '/api/v1/vms',
        $login['session'],
        $login['xsrf'],
    );
    $suspendedResponse->assertStatus(403)
        ->assertJsonFragment(['message' => __('messages.auth.account_suspended')]);
});

test('session expires with 401 once absolute 1440-minute lifetime is exceeded despite recent activity', function (): void {
    $start = Carbon::create(2026, 11, 26, 12, 0, 0, 'UTC');
    Carbon::setTestNow($start);

    $user = createActiveCustomer('SecretPass123!', 'absolute-cap@example.com');
    $login = performStatefulLogin($this, $user->email, 'SecretPass123!');
    $login['response']->assertOk();

    $session = $login['session'];
    $xsrf = $login['xsrf'];

    // Keep idle lifetime and session storage fresh every 100 minutes up to 1400 minutes
    for ($minutes = 100; $minutes <= 1400; $minutes += 100) {
        Carbon::setTestNow($start->copy()->addMinutes($minutes));
        sendStatefulRequest($this, 'GET', '/api/v1/me', $session, $xsrf)->assertOk();
    }

    // Only 41 minutes after the last active request, but 1441 minutes past login_at
    Carbon::setTestNow($start->copy()->addMinutes(1441));
    sendStatefulRequest($this, 'GET', '/api/v1/me', $session, $xsrf)
        ->assertUnauthorized()
        ->assertJson(['message' => 'Session expired.']);
});

test('passive requests do not extend idle lifetime while non-passive requests do', function (): void {
    $start = Carbon::create(2026, 11, 26, 12, 0, 0, 'UTC');
    Carbon::setTestNow($start);

    $user = createActiveCustomer('SecretPass123!', 'idle-passive@example.com');
    $firstLogin = performStatefulLogin($this, $user->email, 'SecretPass123!');
    $firstLogin['response']->assertOk();

    sendStatefulRequest($this, 'GET', '/api/v1/me', $firstLogin['session'], $firstLogin['xsrf'])
        ->assertOk();

    // Passive poll at +90m must NOT advance last_activity_at
    Carbon::setTestNow($start->copy()->addMinutes(90));
    sendStatefulRequest(
        $this,
        'GET',
        '/api/v1/me',
        $firstLogin['session'],
        $firstLogin['xsrf'],
        [],
        'http://localhost',
        ['X-Ognivo-Activity' => 'passive'],
    )->assertOk();

    // At +121m from initial activity, session expires even though passive poll ran at +90m
    Carbon::setTestNow($start->copy()->addMinutes(121));
    sendStatefulRequest($this, 'GET', '/api/v1/me', $firstLogin['session'], $firstLogin['xsrf'])
        ->assertUnauthorized()
        ->assertJson(['message' => 'Session expired.']);

    // Second login: non-passive request at +90m MUST extend last_activity_at
    $secondStart = Carbon::create(2026, 11, 26, 15, 0, 0, 'UTC');
    Carbon::setTestNow($secondStart);
    app('auth')->forgetGuards();

    $secondLogin = performStatefulLogin($this, $user->email, 'SecretPass123!');
    $secondLogin['response']->assertOk();

    sendStatefulRequest($this, 'GET', '/api/v1/me', $secondLogin['session'], $secondLogin['xsrf'])
        ->assertOk();

    Carbon::setTestNow($secondStart->copy()->addMinutes(90));
    sendStatefulRequest($this, 'GET', '/api/v1/me', $secondLogin['session'], $secondLogin['xsrf'])
        ->assertOk();

    // At +150m from login (60m after non-passive activity), session is still valid
    Carbon::setTestNow($secondStart->copy()->addMinutes(150));
    sendStatefulRequest($this, 'GET', '/api/v1/me', $secondLogin['session'], $secondLogin['xsrf'])
        ->assertOk();

    // Passive broadcasting auth poll at +210m must NOT extend last_activity_at beyond +150m
    Carbon::setTestNow($secondStart->copy()->addMinutes(210));
    sendStatefulRequest(
        $this,
        'POST',
        '/api/v1/broadcasting/auth',
        $secondLogin['session'],
        $secondLogin['xsrf'],
        [
            'channel_name' => 'private-users.'.$user->id,
            'socket_id' => '1234.5678',
        ],
        'http://localhost',
        ['X-Ognivo-Activity' => 'passive'],
    )->assertOk();

    // At +271m from login (121m after +150m non-passive activity), idle expires in EnforceSessionLifetimes
    Carbon::setTestNow($secondStart->copy()->addMinutes(271));
    sendStatefulRequest($this, 'GET', '/api/v1/me', $secondLogin['session'], $secondLogin['xsrf'])
        ->assertUnauthorized()
        ->assertJson(['message' => 'Session expired.']);
});

test('changing password invalidates a second active session for the same user', function (): void {
    $user = createActiveCustomer('OldSecretPass123!', 'multi-session@example.com');

    $sessionA = performStatefulLogin($this, $user->email, 'OldSecretPass123!');
    $sessionA['response']->assertOk();

    app('auth')->forgetGuards();

    $sessionB = performStatefulLogin($this, $user->email, 'OldSecretPass123!');
    $sessionB['response']->assertOk();

    expect($sessionB['decryptedSessionId'])->not->toBe($sessionA['decryptedSessionId']);

    sendStatefulRequest($this, 'GET', '/api/v1/me', $sessionB['session'], $sessionB['xsrf'])
        ->assertOk();

    sendStatefulRequest(
        $this,
        'PUT',
        '/api/v1/me',
        $sessionA['session'],
        $sessionA['xsrf'],
        [
            'name' => $user->name,
            'email' => $user->email,
            'current_password' => 'OldSecretPass123!',
            'password' => 'NewSecretPass456!',
            'password_confirmation' => 'NewSecretPass456!',
        ],
    )->assertOk();

    // Session A remains authenticated after updating its stored password hash
    sendStatefulRequest($this, 'GET', '/api/v1/me', $sessionA['session'], $sessionA['xsrf'])
        ->assertOk();

    // Session B holds the stale password hash and is rejected by AuthenticateSession
    sendStatefulRequest($this, 'GET', '/api/v1/me', $sessionB['session'], $sessionB['xsrf'])
        ->assertUnauthorized();
});
