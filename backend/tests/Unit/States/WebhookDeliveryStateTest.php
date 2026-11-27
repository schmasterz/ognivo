<?php

declare(strict_types=1);

use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\States\Webhook\Failed;
use App\States\Webhook\Pending;
use App\States\Webhook\Retrying;
use App\States\Webhook\Success;
use App\States\Webhook\WebhookDeliveryState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\ModelStates\Exceptions\CouldNotPerformTransition;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('defaults WebhookDelivery status to Pending', function (): void {
    $delivery = new WebhookDelivery;

    expect($delivery->status)
        ->toBeInstanceOf(WebhookDeliveryState::class)
        ->toBeInstanceOf(Pending::class)
        ->and($delivery->status->getValue())->toBe('pending');
});

it('allows valid WebhookDeliveryState transitions', function (string $from, string $to): void {
    $endpoint = WebhookEndpoint::query()->create([
        'url' => 'https://hooks.example.test/state-allowed',
        'secret' => 'whsec_state_allowed_1234567890',
        'subscribed_events' => ['*'],
    ]);

    $delivery = WebhookDelivery::query()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'event_name' => 'instance.created',
        'payload' => ['instance_id' => 1],
        'status' => $from,
    ]);

    $delivery->status->transitionTo($to);

    $fresh = $delivery->fresh();
    expect($fresh)->not->toBeNull()
        ->and($fresh?->status)->toBeInstanceOf($to);
})->with([
    'Pending -> Retrying' => [Pending::class, Retrying::class],
    'Pending -> Success' => [Pending::class, Success::class],
    'Pending -> Failed' => [Pending::class, Failed::class],
    'Retrying -> Success' => [Retrying::class, Success::class],
    'Retrying -> Failed' => [Retrying::class, Failed::class],
]);

it('rejects disallowed WebhookDeliveryState transitions', function (string $from, string $to): void {
    $endpoint = WebhookEndpoint::query()->create([
        'url' => 'https://hooks.example.test/state-disallowed',
        'secret' => 'whsec_state_disallowed_1234567890',
        'subscribed_events' => ['*'],
    ]);

    $delivery = WebhookDelivery::query()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'event_name' => 'instance.created',
        'payload' => ['instance_id' => 1],
        'status' => $from,
    ]);

    expect(fn () => $delivery->status->transitionTo($to))
        ->toThrow(CouldNotPerformTransition::class);

    expect($delivery->fresh()?->status)->toBeInstanceOf($from);
})->with([
    'Pending -> Pending' => [Pending::class, Pending::class],
    'Retrying -> Pending' => [Retrying::class, Pending::class],
    'Retrying -> Retrying' => [Retrying::class, Retrying::class],
    'Success -> Pending' => [Success::class, Pending::class],
    'Success -> Retrying' => [Success::class, Retrying::class],
    'Success -> Success' => [Success::class, Success::class],
    'Success -> Failed' => [Success::class, Failed::class],
    'Failed -> Pending' => [Failed::class, Pending::class],
    'Failed -> Retrying' => [Failed::class, Retrying::class],
    'Failed -> Success' => [Failed::class, Success::class],
    'Failed -> Failed' => [Failed::class, Failed::class],
]);
