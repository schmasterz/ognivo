<?php

declare(strict_types=1);

namespace App\States\Webhook;

/**
 * Terminal state representing a permanently failed webhook delivery after exhausting retries.
 */
final class Failed extends WebhookDeliveryState
{
    public static string $name = 'failed';
}
