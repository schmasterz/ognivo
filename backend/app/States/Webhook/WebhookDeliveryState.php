<?php

declare(strict_types=1);

namespace App\States\Webhook;

use App\Models\WebhookDelivery;
use Override;
use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

/**
 * Base abstract state for Webhook delivery attempts.
 *
 * @extends State<WebhookDelivery>
 */
abstract class WebhookDeliveryState extends State
{
    /**
     * Configure state transitions.
     */
    #[Override]
    public static function config(): StateConfig
    {
        return parent::config()
            ->default(Pending::class)
            ->allowTransition(Pending::class, Retrying::class)
            ->allowTransition(Pending::class, Success::class)
            ->allowTransition(Pending::class, Failed::class)
            ->allowTransition(Retrying::class, Success::class)
            ->allowTransition(Retrying::class, Failed::class);
    }
}
