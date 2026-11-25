<?php

declare(strict_types=1);

use App\Events\Instance\InstanceCreated;
use App\Jobs\DeliverWebhookJob;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('dispatches DeliverWebhookJob for matching active endpoints when a domain event fires', function (): void {
    Queue::fake();

    $exactEndpoint = WebhookEndpoint::query()->create([
        'url' => 'https://hooks.example.test/exact',
        'secret' => 'whsec_exact_1234567890',
        'subscribed_events' => ['instance.created'],
        'is_active' => true,
    ]);

    $wildcardEndpoint = WebhookEndpoint::query()->create([
        'url' => 'https://hooks.example.test/wildcard',
        'secret' => 'whsec_wildcard_1234567890',
        'subscribed_events' => ['*'],
        'is_active' => true,
    ]);

    $prefixEndpoint = WebhookEndpoint::query()->create([
        'url' => 'https://hooks.example.test/prefix',
        'secret' => 'whsec_prefix_1234567890',
        'subscribed_events' => ['instance.*'],
        'is_active' => true,
    ]);

    $otherEventEndpoint = WebhookEndpoint::query()->create([
        'url' => 'https://hooks.example.test/other',
        'secret' => 'whsec_other_1234567890',
        'subscribed_events' => ['host.registered'],
        'is_active' => true,
    ]);

    $inactiveEndpoint = WebhookEndpoint::query()->create([
        'url' => 'https://hooks.example.test/inactive',
        'secret' => 'whsec_inactive_1234567890',
        'subscribed_events' => ['*'],
        'is_active' => false,
    ]);

    $event = new InstanceCreated(
        instanceId: 42,
        name: 'web-01',
        hostname: 'web-01.example.test',
        cpu: 2,
        memoryMb: 2048,
        diskGb: 20,
        image: 'ubuntu/24.04',
    );

    event($event);

    expect(WebhookDelivery::query()->count())->toBe(3);

    foreach ([$exactEndpoint, $wildcardEndpoint, $prefixEndpoint] as $matchingEndpoint) {
        $delivery = WebhookDelivery::query()
            ->where('webhook_endpoint_id', $matchingEndpoint->id)
            ->first();

        expect($delivery)->not->toBeNull()
            ->and($delivery?->status)->toBe(WebhookDelivery::STATUS_PENDING)
            ->and($delivery?->event_name)->toBe('instance.created')
            ->and($delivery?->payload)->toBe($event->getPayload())
            ->and($delivery?->attempt)->toBe(1);
    }

    expect(WebhookDelivery::query()->where('webhook_endpoint_id', $otherEventEndpoint->id)->exists())->toBeFalse()
        ->and(WebhookDelivery::query()->where('webhook_endpoint_id', $inactiveEndpoint->id)->exists())->toBeFalse();

    Queue::assertPushed(DeliverWebhookJob::class, 3);
    Queue::assertPushed(DeliverWebhookJob::class, static function (DeliverWebhookJob $job) use ($exactEndpoint): bool {
        return $job->delivery->webhook_endpoint_id === $exactEndpoint->id;
    });
});

it('configures exponential backoff and retry count on DeliverWebhookJob', function (): void {
    $endpoint = WebhookEndpoint::query()->create([
        'url' => 'https://hooks.example.test/config',
        'secret' => 'whsec_config_1234567890',
        'subscribed_events' => ['*'],
    ]);

    $delivery = WebhookDelivery::query()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'event_name' => 'instance.created',
        'payload' => ['instance_id' => 1],
    ]);

    $job = new DeliverWebhookJob($delivery);

    expect($job->tries)->toBe(4)
        ->and($job->backoff)->toBe([60, 300, 900, 3600]);
});

it('delivers signed JSON payload with expected headers and updates delivery on HTTP 2xx', function (): void {
    $secret = 'whsec_signing_secret_987654321';

    $endpoint = WebhookEndpoint::query()->create([
        'url' => 'https://hooks.example.test/receive',
        'secret' => $secret,
        'subscribed_events' => ['instance.created'],
        'failure_count' => 3,
    ]);

    $payload = [
        'instance_id' => 99,
        'name' => 'api-01',
        'hostname' => 'api-01.example.test',
    ];

    $delivery = WebhookDelivery::query()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'event_name' => 'instance.created',
        'payload' => $payload,
        'status' => WebhookDelivery::STATUS_PENDING,
        'attempt' => 1,
    ]);

    Http::fake([
        'https://hooks.example.test/receive' => Http::response('{"accepted":true}', 200),
    ]);

    (new DeliverWebhookJob($delivery))->handle();

    $expectedJson = json_encode($payload, JSON_THROW_ON_ERROR);
    $expectedSignature = 'sha256='.hash_hmac('sha256', $expectedJson, $secret);

    Http::assertSent(static function (Request $request) use ($endpoint, $delivery, $expectedJson, $expectedSignature): bool {
        return $request->method() === 'POST'
            && $request->url() === $endpoint->url
            && $request->body() === $expectedJson
            && $request->hasHeader('Content-Type', 'application/json')
            && $request->hasHeader('X-Ognivo-Event', 'instance.created')
            && $request->hasHeader('X-Ognivo-Signature', $expectedSignature)
            && $request->hasHeader('X-Ognivo-Delivery', (string) $delivery->id);
    });

    $delivery->refresh();
    $endpoint->refresh();

    expect($delivery->status)->toBe(WebhookDelivery::STATUS_SUCCESS)
        ->and($delivery->response_status)->toBe(200)
        ->and($delivery->response_body)->toBe('{"accepted":true}')
        ->and($delivery->duration_ms)->toBeInt()->toBeGreaterThanOrEqual(0)
        ->and($delivery->attempt)->toBe(1)
        ->and($endpoint->failure_count)->toBe(0);
});

it('truncates response body to 1000 characters on long responses', function (): void {
    $endpoint = WebhookEndpoint::query()->create([
        'url' => 'https://hooks.example.test/long',
        'secret' => 'whsec_long_1234567890',
        'subscribed_events' => ['*'],
    ]);

    $delivery = WebhookDelivery::query()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'event_name' => 'instance.started',
        'payload' => ['instance_id' => 7],
    ]);

    Http::fake([
        'https://hooks.example.test/long' => Http::response(str_repeat('a', 1500), 202),
    ]);

    (new DeliverWebhookJob($delivery))->handle();

    $delivery->refresh();

    expect($delivery->status)->toBe(WebhookDelivery::STATUS_SUCCESS)
        ->and($delivery->response_status)->toBe(202)
        ->and(mb_strlen((string) $delivery->response_body))->toBe(1000);
});

it('marks delivery failed, increments endpoint failure count, and throws on HTTP 4xx/5xx', function (int $statusCode): void {
    $endpoint = WebhookEndpoint::query()->create([
        'url' => 'https://hooks.example.test/error',
        'secret' => 'whsec_error_1234567890',
        'subscribed_events' => ['*'],
        'failure_count' => 1,
    ]);

    $delivery = WebhookDelivery::query()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'event_name' => 'instance.stopped',
        'payload' => ['instance_id' => 15],
        'status' => WebhookDelivery::STATUS_PENDING,
    ]);

    Http::fake([
        'https://hooks.example.test/error' => Http::response('Delivery rejected', $statusCode),
    ]);

    expect(fn () => (new DeliverWebhookJob($delivery))->handle())
        ->toThrow(RequestException::class);

    $delivery->refresh();
    $endpoint->refresh();

    expect($delivery->status)->toBe(WebhookDelivery::STATUS_FAILED)
        ->and($delivery->response_status)->toBe($statusCode)
        ->and($delivery->response_body)->toBe('Delivery rejected')
        ->and($delivery->duration_ms)->toBeInt()->toBeGreaterThanOrEqual(0)
        ->and($endpoint->failure_count)->toBe(2);
})->with([400, 422, 500, 503]);

it('marks delivery failed, increments endpoint failure count, and rethrows ConnectionException', function (): void {
    $endpoint = WebhookEndpoint::query()->create([
        'url' => 'https://hooks.example.test/timeout',
        'secret' => 'whsec_timeout_1234567890',
        'subscribed_events' => ['*'],
        'failure_count' => 0,
    ]);

    $delivery = WebhookDelivery::query()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'event_name' => 'instance.terminated',
        'payload' => ['instance_id' => 25],
        'status' => WebhookDelivery::STATUS_PENDING,
    ]);

    Http::fake([
        'https://hooks.example.test/timeout' => static function (): never {
            throw new ConnectionException('Connection timed out after 10000 milliseconds');
        },
    ]);

    expect(fn () => (new DeliverWebhookJob($delivery))->handle())
        ->toThrow(ConnectionException::class);

    $delivery->refresh();
    $endpoint->refresh();

    expect($delivery->status)->toBe(WebhookDelivery::STATUS_FAILED)
        ->and($delivery->response_status)->toBeNull()
        ->and($delivery->response_body)->toContain('Connection timed out')
        ->and($delivery->duration_ms)->toBeInt()->toBeGreaterThanOrEqual(0)
        ->and($endpoint->failure_count)->toBe(1);
});

it('updates delivery status and throws RequestException on 504 Gateway Timeout', function (): void {
    $endpoint = WebhookEndpoint::query()->create([
        'url' => 'https://hooks.example.test/exhausted',
        'secret' => 'whsec_exhausted_1234567890',
        'subscribed_events' => ['*'],
    ]);

    $delivery = WebhookDelivery::query()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'event_name' => 'instance.resized',
        'payload' => ['instance_id' => 33],
    ]);

    Http::fake([
        'https://hooks.example.test/exhausted' => Http::response('Gateway Timeout', 504),
    ]);

    $job = (new DeliverWebhookJob($delivery))->withFakeQueueInteractions();

    expect(fn () => $job->handle())->toThrow(RequestException::class);

    $delivery->refresh();
    $endpoint->refresh();

    expect($delivery->status)->toBe(WebhookDelivery::STATUS_FAILED)
        ->and($delivery->response_status)->toBe(504)
        ->and($endpoint->failure_count)->toBe(1);
});
