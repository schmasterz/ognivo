<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class OpenApiCookieSchemeTest extends TestCase
{
    public function test_openapi_spec_uses_cookie_auth_scheme_and_documents_auth_endpoints(): void
    {
        $tempPath = sys_get_temp_dir().'/openapi_cookie_scheme_'.uniqid('', true).'.json';

        try {
            Artisan::call('scramble:clear');
            $exitCode = Artisan::call('scramble:export', ['--path' => $tempPath]);

            $this->assertSame(0, $exitCode);
            $this->assertFileExists($tempPath);

            $raw = file_get_contents($tempPath);
            $this->assertIsString($raw);

            /** @var array<string, mixed> $spec */
            $spec = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            /** @var array<string, array<string, mixed>> $schemes */
            $schemes = $spec['components']['securitySchemes'] ?? [];

            $this->assertArrayHasKey('cookieAuth', $schemes);
            $this->assertSame('apiKey', $schemes['cookieAuth']['type'] ?? null);
            $this->assertSame('cookie', $schemes['cookieAuth']['in'] ?? null);
            $this->assertSame((string) config('session.cookie'), $schemes['cookieAuth']['name'] ?? null);

            foreach ($schemes as $scheme) {
                $this->assertNotSame('http', $scheme['type'] ?? null);
                $this->assertNotSame('bearer', $scheme['scheme'] ?? null);
            }

            /** @var array<string, array<string, mixed>> $paths */
            $paths = $spec['paths'] ?? [];

            $this->assertArrayHasKey('/v1/login', $paths);
            $this->assertArrayHasKey('post', $paths['/v1/login']);
            /** @var array<string, mixed> $loginOperation */
            $loginOperation = $paths['/v1/login']['post'];
            $this->assertSame([], $loginOperation['security'] ?? []);

            /** @var array<int|string, mixed> $loginResponses */
            $loginResponses = $loginOperation['responses'] ?? [];
            foreach (['200', '401', '419', '422', '429'] as $statusCode) {
                $this->assertArrayHasKey($statusCode, $loginResponses);
            }

            $this->assertArrayHasKey('/v1/logout', $paths);
            $this->assertArrayHasKey('post', $paths['/v1/logout']);
            /** @var array<int|string, mixed> $logoutResponses */
            $logoutResponses = $paths['/v1/logout']['post']['responses'] ?? [];
            foreach (['200', '401', '419'] as $statusCode) {
                $this->assertArrayHasKey($statusCode, $logoutResponses);
            }

            $this->assertArrayHasKey('/v1/me', $paths);
            $this->assertArrayHasKey('get', $paths['/v1/me']);
            /** @var array<string, mixed> $meOperation */
            $meOperation = $paths['/v1/me']['get'];
            /** @var array<int|string, mixed> $meResponses */
            $meResponses = $meOperation['responses'] ?? [];
            foreach (['200', '401'] as $statusCode) {
                $this->assertArrayHasKey($statusCode, $meResponses);
            }

            /** @var array<int, array<string, mixed>> $meEffectiveSecurity */
            $meEffectiveSecurity = $meOperation['security'] ?? $spec['security'] ?? [];
            $this->assertNotEmpty($meEffectiveSecurity);

            $referencesCookieAuth = false;
            foreach ($meEffectiveSecurity as $requirement) {
                if (is_array($requirement) && array_key_exists('cookieAuth', $requirement)) {
                    $referencesCookieAuth = true;
                    break;
                }
            }

            $this->assertTrue($referencesCookieAuth, 'GET /v1/me must require the cookieAuth security scheme.');
        } finally {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    public function test_scramble_defaults_disable_try_it_and_use_same_origin_credentials(): void
    {
        $this->assertSame((bool) env('SCRAMBLE_ALLOW_PUBLIC', false), (bool) config('scramble.allow_public'));
        $this->assertTrue((bool) config('scramble.renderers.elements.hideTryIt'));
        $this->assertSame('same-origin', config('scramble.renderers.elements.tryItCredentialsPolicy'));
        $this->assertSame('same-origin', config('scramble.renderers.scalar.credentials'));
    }
}
