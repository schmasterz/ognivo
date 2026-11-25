<?php

declare(strict_types=1);

use App\Audit\AuditOutcome;
use App\Jobs\DeliverWebhookJob;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Feature\AuditAssertionTrait;

uses(AuditAssertionTrait::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

    $this->admin = User::factory()->create([
        'name' => 'Root Admin',
        'email' => 'admin@ognivo.test',
        'status' => 'active',
    ]);
    $this->admin->assignRole('admin');

    $this->customer = User::factory()->create([
        'name' => 'Regular Customer',
        'email' => 'customer@ognivo.test',
        'status' => 'active',
    ]);
    $this->customer->assignRole('customer');
});

test('unauthenticated users receive 401 for webhook endpoints', function (): void {
    $endpoint = WebhookEndpoint::query()->create([
        'url' => 'https://hooks.example.test/unauth',
        'secret' => bin2hex(random_bytes(32)),
        'subscribed_events' => ['*'],
    ]);

    $this->getJson('/api/v1/webhooks')->assertUnauthorized();
    $this->postJson('/api/v1/webhooks', [
        'url' => 'https://hooks.example.test/new',
        'subscribed_events' => ['instance.created'],
    ])->assertUnauthorized();
    $this->deleteJson("/api/v1/webhooks/{$endpoint->id}")->assertUnauthorized();
    $this->getJson("/api/v1/webhooks/{$endpoint->id}/deliveries")->assertUnauthorized();
    $this->postJson("/api/v1/webhooks/{$endpoint->id}/test")->assertUnauthorized();
});

test('non-admin users receive 403 for webhook endpoints', function (): void {
    $endpoint = WebhookEndpoint::query()->create([
        'url' => 'https://hooks.example.test/forbidden',
        'secret' => bin2hex(random_bytes(32)),
        'subscribed_events' => ['*'],
    ]);

    Sanctum::actingAs($this->customer);

    $this->getJson('/api/v1/webhooks')->assertForbidden();
    $this->postJson('/api/v1/webhooks', [
        'url' => 'https://hooks.example.test/new',
        'subscribed_events' => ['instance.created'],
    ])->assertForbidden();
    $this->deleteJson("/api/v1/webhooks/{$endpoint->id}")->assertForbidden();
    $this->getJson("/api/v1/webhooks/{$endpoint->id}/deliveries")->assertForbidden();
    $this->postJson("/api/v1/webhooks/{$endpoint->id}/test")->assertForbidden();
});

test('admin can list webhook endpoints without exposing secrets', function (): void {
    $first = WebhookEndpoint::query()->create([
        'url' => 'https://hooks.example.test/first',
        'secret' => bin2hex(random_bytes(32)),
        'subscribed_events' => ['instance.created'],
        'is_active' => true,
        'failure_count' => 0,
    ]);
    $second = WebhookEndpoint::query()->create([
        'url' => 'https://hooks.example.test/second',
        'secret' => bin2hex(random_bytes(32)),
        'subscribed_events' => ['instance.*', 'host.registered'],
        'is_active' => false,
        'failure_count' => 3,
    ]);

    Sanctum::actingAs($this->admin);

    $response = $this->getJson('/api/v1/webhooks');

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'url',
                    'subscribed_events',
                    'is_active',
                    'failure_count',
                    'created_at',
                    'updated_at',
                ],
            ],
        ]);

    /** @var array<int, array<string, mixed>> $items */
    $items = $response->json('data');
    expect($items[0])->not->toHaveKey('secret')
        ->and($items[1])->not->toHaveKey('secret')
        ->and(collect($items)->pluck('id')->all())->toBe([$second->id, $first->id]);
});

test('admin can create a webhook endpoint and receives a 64-char hex secret and audit log', function (): void {
    Sanctum::actingAs($this->admin);

    $response = $this->postJson('/api/v1/webhooks', [
        'url' => 'https://hooks.example.test/events',
        'subscribed_events' => ['instance.created', 'instance.*'],
    ]);

    $response->assertCreated()
        ->assertJsonStructure([
            'data' => [
                'id',
                'url',
                'subscribed_events',
                'is_active',
                'failure_count',
                'created_at',
                'updated_at',
            ],
            'secret',
        ]);

    /** @var string $secret */
    $secret = $response->json('secret');
    /** @var array<string, mixed> $endpointPayload */
    $endpointPayload = $response->json('data');

    expect($secret)->toMatch('/^[0-9a-f]{64}$/')
        ->and($endpointPayload)->not->toHaveKey('secret')
        ->and($endpointPayload['url'])->toBe('https://hooks.example.test/events')
        ->and($endpointPayload['subscribed_events'])->toBe(['instance.created', 'instance.*'])
        ->and($endpointPayload['is_active'])->toBeTrue()
        ->and($endpointPayload['failure_count'])->toBe(0);

    $createdEndpoint = WebhookEndpoint::query()->find($endpointPayload['id']);
    expect($createdEndpoint)->not->toBeNull()
        ->and($createdEndpoint?->secret)->toBe($secret)
        ->and($createdEndpoint?->secret)->toMatch('/^[0-9a-f]{64}$/');

    $auditRow = $this->assertAuditRow(
        action: 'webhook.created',
        outcome: AuditOutcome::Success->value,
        tenantUserId: null,
        visibility: 'admin',
    );

    expect($auditRow->subject_type)->toBe(WebhookEndpoint::class)
        ->and($auditRow->subject_id)->toBe((string) $createdEndpoint?->id)
        ->and($auditRow->actor_id)->toBe($this->admin->id);
});

test('webhook creation validates required fields and constraints', function (): void {
    Sanctum::actingAs($this->admin);

    $this->postJson('/api/v1/webhooks', [
        'url' => 'not-a-valid-url',
        'subscribed_events' => [],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['url', 'subscribed_events']);

    $this->postJson('/api/v1/webhooks', [
        'url' => 'https://hooks.example.test/'.str_repeat('a', 500),
        'subscribed_events' => [str_repeat('b', 101)],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['url', 'subscribed_events.0']);
});

test('admin can delete a webhook endpoint and records audit log', function (): void {
    $endpoint = WebhookEndpoint::query()->create([
        'url' => 'https://hooks.example.test/delete-me',
        'secret' => bin2hex(random_bytes(32)),
        'subscribed_events' => ['*'],
    ]);

    WebhookDelivery::query()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'event_name' => 'instance.created',
        'payload' => ['instance_id' => 1],
    ]);

    Sanctum::actingAs($this->admin);

    $response = $this->deleteJson("/api/v1/webhooks/{$endpoint->id}");

    $response->assertOk()
        ->assertJson(['message' => 'Webhook endpoint deleted successfully.']);

    $this->assertDatabaseMissing('webhook_endpoints', ['id' => $endpoint->id]);
    $this->assertDatabaseMissing('webhook_deliveries', ['webhook_endpoint_id' => $endpoint->id]);

    $auditRow = $this->assertAuditRow(
        action: 'webhook.deleted',
        outcome: AuditOutcome::Success->value,
        tenantUserId: null,
        visibility: 'admin',
    );

    expect($auditRow->subject_type)->toBe(WebhookEndpoint::class)
        ->and($auditRow->subject_id)->toBe((string) $endpoint->id)
        ->and($auditRow->actor_id)->toBe($this->admin->id);
});

test('admin can list deliveries for a webhook endpoint ordered by id descending', function (): void {
    $endpoint = WebhookEndpoint::query()->create([
        'url' => 'https://hooks.example.test/deliveries',
        'secret' => bin2hex(random_bytes(32)),
        'subscribed_events' => ['*'],
    ]);
    $otherEndpoint = WebhookEndpoint::query()->create([
        'url' => 'https://hooks.example.test/other',
        'secret' => bin2hex(random_bytes(32)),
        'subscribed_events' => ['*'],
    ]);

    $older = WebhookDelivery::query()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'event_name' => 'instance.created',
        'payload' => ['instance_id' => 10],
        'response_status' => 500,
        'attempt' => 1,
        'duration_ms' => 120,
        'status' => WebhookDelivery::STATUS_FAILED,
    ]);
    $newer = WebhookDelivery::query()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'event_name' => 'instance.started',
        'payload' => ['instance_id' => 10],
        'response_status' => 200,
        'attempt' => 1,
        'duration_ms' => 45,
        'status' => WebhookDelivery::STATUS_SUCCESS,
    ]);
    WebhookDelivery::query()->create([
        'webhook_endpoint_id' => $otherEndpoint->id,
        'event_name' => 'host.registered',
        'payload' => ['host_id' => 2],
        'response_status' => 200,
        'attempt' => 1,
        'duration_ms' => 30,
        'status' => WebhookDelivery::STATUS_SUCCESS,
    ]);

    Sanctum::actingAs($this->admin);

    $response = $this->getJson("/api/v1/webhooks/{$endpoint->id}/deliveries");

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'event_name',
                    'response_status',
                    'attempt',
                    'duration_ms',
                    'status',
                    'created_at',
                ],
            ],
            'meta' => [
                'current_page',
                'last_page',
                'per_page',
                'total',
            ],
        ]);

    /** @var array<int, array<string, mixed>> $items */
    $items = $response->json('data');
    expect(collect($items)->pluck('id')->all())->toBe([$newer->id, $older->id])
        ->and($items[0]['event_name'])->toBe('instance.started')
        ->and($items[0]['response_status'])->toBe(200)
        ->and($items[0]['status'])->toBe(WebhookDelivery::STATUS_SUCCESS)
        ->and($response->json('meta.total'))->toBe(2);
});

test('admin can trigger a test webhook ping and queues DeliverWebhookJob', function (): void {
    Queue::fake();

    $endpoint = WebhookEndpoint::query()->create([
        'url' => 'https://hooks.example.test/ping',
        'secret' => bin2hex(random_bytes(32)),
        'subscribed_events' => ['instance.created'],
    ]);

    Sanctum::actingAs($this->admin);

    $response = $this->postJson("/api/v1/webhooks/{$endpoint->id}/test");

    $response->assertAccepted()
        ->assertJsonStructure([
            'message',
            'delivery_id',
        ])
        ->assertJson([
            'message' => 'Test webhook queued successfully.',
        ]);

    /** @var int $deliveryId */
    $deliveryId = $response->json('delivery_id');

    $delivery = WebhookDelivery::query()->find($deliveryId);
    expect($delivery)->not->toBeNull()
        ->and($delivery?->webhook_endpoint_id)->toBe($endpoint->id)
        ->and($delivery?->event_name)->toBe('ping')
        ->and($delivery?->status)->toBe(WebhookDelivery::STATUS_PENDING)
        ->and($delivery?->payload['ping'] ?? null)->toBeTrue()
        ->and($delivery?->payload['timestamp'] ?? null)->toBeString();

    Queue::assertPushed(DeliverWebhookJob::class, static function (DeliverWebhookJob $job) use ($deliveryId): bool {
        return $job->delivery->id === $deliveryId;
    });
});

test('operations on a non-existent webhook endpoint return 404', function (): void {
    Sanctum::actingAs($this->admin);

    $this->deleteJson('/api/v1/webhooks/999999')->assertNotFound();
    $this->getJson('/api/v1/webhooks/999999/deliveries')->assertNotFound();
    $this->postJson('/api/v1/webhooks/999999/test')->assertNotFound();
});
