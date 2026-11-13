<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Override;

/**
 * Queued email verification notification handled asynchronously via Redis queue worker.
 */
final class QueuedVerifyEmailNotification extends VerifyEmail implements ShouldQueue
{
    use Queueable;

    /**
     * Build the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     */
    #[Override]
    public function toMail($notifiable): MailMessage
    {
        /** @var \App\Services\SystemOverviewService $overviewService */
        $overviewService = app(\App\Services\SystemOverviewService::class);
        $overviewService->applyMailConfig();

        $verificationUrl = $this->verificationUrl($notifiable);

        $name = ($notifiable instanceof User) ? $notifiable->name : 'User';

        return (new MailMessage)
            ->subject(__('messages.auth.verify_email_subject'))
            ->greeting(__('messages.auth.verify_email_greeting', ['name' => $name]))
            ->line(__('messages.auth.verify_email_line1'))
            ->action(__('messages.auth.verify_email_action'), $verificationUrl)
            ->line(__('messages.auth.verify_email_line2'));
    }
}
