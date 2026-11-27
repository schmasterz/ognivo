<?php

declare(strict_types=1);

namespace App\States\Webhook;

/**
 * State representing a newly queued webhook delivery awaiting its first attempt.
 */
final class Pending extends WebhookDeliveryState
{
    public static string $name = 'pending';
}
