<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\Contracts\OgnivoEventInterface;
use App\Jobs\DeliverWebhookJob;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Events\ShouldBeDiscovered;
use Override;

/**
 * Subscribes to all Ognivo domain events and dispatches queued webhook deliveries.
 */
final class WebhookEventSubscriber implements ShouldBeDiscovered
{
    /**
     * Prevent duplicate registration via event auto-discovery; registered explicitly via Event::subscribe.
     */
    #[Override]
    public static function shouldBeDiscovered(): bool
    {
        return false;
    }

    /**
     * Handle a domain event and queue deliveries for all matching active webhook endpoints.
     */
    public function handle(OgnivoEventInterface $event): void
    {
        $eventName = $event->getEventName();
        $payload = $event->getPayload();

        $endpoints = WebhookEndpoint::query()
            ->where('is_active', true)
            ->get();

        foreach ($endpoints as $endpoint) {
            if (! $endpoint->isSubscribedTo($eventName)) {
                continue;
            }

            $delivery = WebhookDelivery::query()->create([
                'webhook_endpoint_id' => $endpoint->id,
                'event_name' => $eventName,
                'payload' => $payload,
                'attempt' => 1,
                'status' => WebhookDelivery::STATUS_PENDING,
            ]);

            DeliverWebhookJob::dispatch($delivery);
        }
    }

    /**
     * Register the listeners for the subscriber.
     */
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(
            OgnivoEventInterface::class,
            [self::class, 'handle'],
        );
    }
}
