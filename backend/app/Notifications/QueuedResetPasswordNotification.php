<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Queued password reset notification handled asynchronously via Redis queue worker.
 */
final class QueuedResetPasswordNotification extends ResetPassword implements ShouldQueue
{
    use Queueable;
}
