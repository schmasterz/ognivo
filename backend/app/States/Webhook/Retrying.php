<?php

declare(strict_types=1);

namespace App\States\Webhook;

/**
 * State representing a webhook delivery that failed an attempt and is awaiting retry.
 */
final class Retrying extends WebhookDeliveryState
{
    public static string $name = 'retrying';
}
