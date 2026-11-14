<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class TrustProxiesTest extends TestCase
{
    private const array DEFAULT_TRUSTED_PROXIES = [
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '127.0.0.1',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        TrustProxies::at(self::DEFAULT_TRUSTED_PROXIES);

        Route::get('/_test/client-ip', static fn (Request $request) => response()->json([
            'ip' => $request->ip(),
        ]));
    }

    protected function tearDown(): void
    {
        TrustProxies::at(self::DEFAULT_TRUSTED_PROXIES);

        parent::tearDown();
    }

    public function test_resolves_client_ip_from_x_forwarded_for_when_remote_addr_is_trusted_private_ip(): void
    {
        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '172.18.0.10',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.50',
        ])->getJson('/_test/client-ip');

        $response->assertOk()
            ->assertJson(['ip' => '203.0.113.50']);
    }

    public function test_ignores_x_forwarded_for_when_remote_addr_is_untrusted_public_ip(): void
    {
        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '198.51.100.10',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.50',
        ])->getJson('/_test/client-ip');

        $response->assertOk()
            ->assertJson(['ip' => '198.51.100.10']);
    }

    public function test_resolves_client_ip_from_x_forwarded_for_when_remote_addr_is_loopback(): void
    {
        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.77',
        ])->getJson('/_test/client-ip');

        $response->assertOk()
            ->assertJson(['ip' => '198.51.100.77']);
    }

    public function test_resolves_client_ip_from_other_rfc1918_ranges(): void
    {
        $response10 = $this->withServerVariables([
            'REMOTE_ADDR' => '10.254.1.2',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.88',
        ])->getJson('/_test/client-ip');

        $response10->assertOk()
            ->assertJson(['ip' => '198.51.100.88']);

        $response192 = $this->withServerVariables([
            'REMOTE_ADDR' => '192.168.10.5',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.99',
        ])->getJson('/_test/client-ip');

        $response192->assertOk()
            ->assertJson(['ip' => '198.51.100.99']);
    }

    public function test_wildcard_trusted_proxies_trusts_arbitrary_calling_ip(): void
    {
        TrustProxies::at('*');

        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '198.51.100.10',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.50',
        ])->getJson('/_test/client-ip');

        $response->assertOk()
            ->assertJson(['ip' => '203.0.113.50']);
    }

    public function test_custom_trusted_proxies_list_only_trusts_configured_ips(): void
    {
        TrustProxies::at(['198.51.100.10']);

        $trustedResponse = $this->withServerVariables([
            'REMOTE_ADDR' => '198.51.100.10',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.50',
        ])->getJson('/_test/client-ip');

        $trustedResponse->assertOk()
            ->assertJson(['ip' => '203.0.113.50']);

        $untrustedResponse = $this->withServerVariables([
            'REMOTE_ADDR' => '198.51.100.20',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.50',
        ])->getJson('/_test/client-ip');

        $untrustedResponse->assertOk()
            ->assertJson(['ip' => '198.51.100.20']);
    }
}
