<?php

declare(strict_types=1);

use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('creates a webhook endpoint and delivery with expected casts and relationships', function (): void {
    $endpoint = WebhookEndpoint::query()->create([
        'url' => 'https://hooks.example.test/ognivo',
        'secret' => str_repeat('a', 64),
        'subscribed_events' => ['instance.created', 'host.*'],
    ]);

    expect($endpoint)->toBeInstanceOf(WebhookEndpoint::class)
        ->and($endpoint->exists)->toBeTrue()
        ->and($endpoint->is_active)->toBeTrue()
        ->and($endpoint->failure_count)->toBe(0)
        ->and($endpoint->subscribed_events)->toBe(['instance.created', 'host.*'])
        ->and($endpoint->toArray())->not->toHaveKey('secret');

    $delivery = WebhookDelivery::query()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'event_name' => 'instance.created',
        'payload' => ['instance_id' => 42, 'name' => 'web-01'],
        'response_status' => 200,
        'response_body' => '{"ok":true}',
        'duration_ms' => 125,
        'status' => WebhookDelivery::STATUS_SUCCESS,
    ]);

    expect($delivery)->toBeInstanceOf(WebhookDelivery::class)
        ->and($delivery->exists)->toBeTrue()
        ->and($delivery->webhook_endpoint_id)->toBe($endpoint->id)
        ->and($delivery->payload)->toBe(['instance_id' => 42, 'name' => 'web-01'])
        ->and($delivery->response_status)->toBe(200)
        ->and($delivery->attempt)->toBe(1)
        ->and($delivery->duration_ms)->toBe(125)
        ->and($delivery->status)->toBe('success')
        ->and($delivery->endpoint->is($endpoint))->toBeTrue()
        ->and($endpoint->deliveries)->toHaveCount(1)
        ->and($endpoint->deliveries->first()?->is($delivery))->toBeTrue();
});

it('matches exact events, wildcard *, and wildcard prefixes via isSubscribedTo', function (): void {
    $scopedEndpoint = new WebhookEndpoint([
        'url' => 'https://hooks.example.test/scoped',
        'secret' => 'secret',
        'subscribed_events' => ['host.online', 'instance.*'],
    ]);

    expect($scopedEndpoint->isSubscribedTo('host.online'))->toBeTrue()
        ->and($scopedEndpoint->isSubscribedTo('host.offline'))->toBeFalse()
        ->and($scopedEndpoint->isSubscribedTo('instance.created'))->toBeTrue()
        ->and($scopedEndpoint->isSubscribedTo('instance.power.stopped'))->toBeTrue()
        ->and($scopedEndpoint->isSubscribedTo('instance_other.created'))->toBeFalse()
        ->and($scopedEndpoint->isSubscribedTo('user.created'))->toBeFalse();

    $wildcardEndpoint = new WebhookEndpoint([
        'url' => 'https://hooks.example.test/all',
        'secret' => 'secret',
        'subscribed_events' => ['*'],
    ]);

    expect($wildcardEndpoint->isSubscribedTo('instance.created'))->toBeTrue()
        ->and($wildcardEndpoint->isSubscribedTo('anything.else'))->toBeTrue();

    $emptyEndpoint = new WebhookEndpoint([
        'url' => 'https://hooks.example.test/none',
        'secret' => 'secret',
        'subscribed_events' => [],
    ]);

    expect($emptyEndpoint->isSubscribedTo('instance.created'))->toBeFalse();
});

it('computes expected HMAC-SHA256 signature via signPayload', function (): void {
    $secret = 'whsec_test_secret_key_1234567890';
    $payload = '{"event":"instance.created","id":1}';

    $endpoint = new WebhookEndpoint([
        'url' => 'https://hooks.example.test/signed',
        'secret' => $secret,
        'subscribed_events' => ['*'],
    ]);

    expect($endpoint->signPayload($payload))->toBe(hash_hmac('sha256', $payload, $secret));
});

it('cascade deletes deliveries when the parent webhook endpoint is deleted', function (): void {
    $endpoint = WebhookEndpoint::query()->create([
        'url' => 'https://hooks.example.test/cascade',
        'secret' => 'secret_for_cascade_test',
        'subscribed_events' => ['*'],
    ]);

    $firstDelivery = WebhookDelivery::query()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'event_name' => 'instance.created',
        'payload' => ['id' => 1],
    ]);

    $secondDelivery = WebhookDelivery::query()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'event_name' => 'instance.started',
        'payload' => ['id' => 1],
        'attempt' => 2,
        'status' => WebhookDelivery::STATUS_FAILED,
    ]);

    expect(WebhookDelivery::query()->count())->toBe(2);

    $endpoint->delete();

    expect(WebhookEndpoint::query()->find($endpoint->id))->toBeNull()
        ->and(WebhookDelivery::query()->find($firstDelivery->id))->toBeNull()
        ->and(WebhookDelivery::query()->find($secondDelivery->id))->toBeNull()
        ->and(WebhookDelivery::query()->count())->toBe(0);
});
