<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Host;
use App\Models\PanelCertificate;
use App\Services\Hypervisor\HypervisorGateway;
use App\Services\PanelCertificateService;
use GuzzleHttp\Handler\CurlMultiHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\TestCase;

final class HypervisorGatewayConnectionReuseTest extends TestCase
{
    use RefreshDatabase;

    public function test_panel_certificate_service_caches_active_certificate_in_memory_avoiding_db_queries(): void
    {
        $certService = app(PanelCertificateService::class);
        $certService->generate(365);

        // Warm the in-memory cache after generate() cleared it, then record queries for subsequent calls.
        $initial = $certService->getActiveCertificate();
        $this->assertInstanceOf(PanelCertificate::class, $initial);

        DB::enableQueryLog();
        DB::flushQueryLog();

        for ($i = 0; $i < 10; $i++) {
            $cert = $certService->getActiveCertificate();
            $this->assertInstanceOf(PanelCertificate::class, $cert);
        }

        $this->assertSame(0, count(DB::getQueryLog()));
    }

    public function test_clear_memory_cache_forces_re_query_and_reloads_from_database(): void
    {
        $certService = app(PanelCertificateService::class);
        $certService->generate(365);

        // Populate the in-memory cache before clearing it.
        $certService->getActiveCertificate();
        $certService->clearMemoryCache();

        DB::enableQueryLog();
        DB::flushQueryLog();

        $cert = $certService->getActiveCertificate();

        $this->assertInstanceOf(PanelCertificate::class, $cert);
        $this->assertGreaterThanOrEqual(1, count(DB::getQueryLog()));
    }

    public function test_active_private_key_is_memoized_in_memory(): void
    {
        $certService = app(PanelCertificateService::class);
        $certService->generate(365);

        $key1 = $certService->getActivePrivateKey();
        $key2 = $certService->getActivePrivateKey();

        $this->assertIsString($key1);
        $this->assertNotSame('', $key1);
        $this->assertSame($key1, $key2);
    }

    public function test_gateway_reuses_curl_handler_for_same_host(): void
    {
        $gateway = app(HypervisorGateway::class);
        $host = Host::create([
            'name' => 'reuse-node-1',
            'endpoint_url' => 'https://198.51.100.20:8443',
            'driver' => 'fake',
            'server_public_key_pin' => 'sha256//'.base64_encode(str_repeat('a', 32)),
        ]);

        $handler1 = $gateway->getHandlerForHost($host);
        $handler2 = $gateway->getHandlerForHost($host);

        $this->assertInstanceOf(CurlMultiHandler::class, $handler1);
        $this->assertSame($handler1, $handler2);
    }

    public function test_gateway_isolates_handlers_for_different_hosts(): void
    {
        $gateway = app(HypervisorGateway::class);

        $host1 = Host::create([
            'name' => 'isolated-node-1',
            'endpoint_url' => 'https://198.51.100.21:8443',
            'driver' => 'fake',
            'server_public_key_pin' => 'sha256//'.base64_encode(str_repeat('b', 32)),
        ]);

        $host2 = Host::create([
            'name' => 'isolated-node-2',
            'endpoint_url' => 'https://198.51.100.22:8443',
            'driver' => 'fake',
            'server_public_key_pin' => 'sha256//'.base64_encode(str_repeat('c', 32)),
        ]);

        $this->assertNotSame(
            $gateway->getHandlerForHost($host1),
            $gateway->getHandlerForHost($host2),
        );
    }

    public function test_gateway_resets_handler_on_host_reset_and_all_reset(): void
    {
        $gateway = app(HypervisorGateway::class);
        $host = Host::create([
            'name' => 'reset-node-1',
            'endpoint_url' => 'https://198.51.100.23:8443',
            'driver' => 'fake',
            'server_public_key_pin' => 'sha256//'.base64_encode(str_repeat('d', 32)),
        ]);

        $h1 = $gateway->getHandlerForHost($host);
        $gateway->resetHostHandler($host);
        $h2 = $gateway->getHandlerForHost($host);

        $this->assertNotSame($h1, $h2);

        $gateway->resetHandlers();
        $h3 = $gateway->getHandlerForHost($host);

        $this->assertNotSame($h2, $h3);
    }

    public function test_gateway_client_attaches_reusable_handler_to_pending_request(): void
    {
        app(PanelCertificateService::class)->generate(365);

        $gateway = app(HypervisorGateway::class);
        $host = Host::create([
            'name' => 'client-handler-node',
            'endpoint_url' => 'https://198.51.100.24:8443',
            'driver' => 'fake',
            'server_public_key_pin' => 'sha256//'.base64_encode(str_repeat('e', 32)),
        ]);

        $client = $gateway->client($host);
        $this->assertInstanceOf(PendingRequest::class, $client);

        $reflection = new ReflectionClass($client);
        $handlerProp = $reflection->getProperty('handler');
        $handlerProp->setAccessible(true);

        $this->assertSame($gateway->getHandlerForHost($host), $handlerProp->getValue($client));
    }
}
