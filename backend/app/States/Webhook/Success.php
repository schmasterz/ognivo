<?php

declare(strict_types=1);

namespace App\States\Webhook;

/**
 * Terminal state representing a successfully delivered webhook.
 */
final class Success extends WebhookDeliveryState
{
    public static string $name = 'success';
}
