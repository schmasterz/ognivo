<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class AuditVerificationFailedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly int $firstFailedId,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[CRITICAL] Audit Seal Verification Failed',
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: sprintf(
                '<h2>Audit Verification Alert</h2><p>Tamper evidence detected: audit seal verification failed at seal ID: <strong>#%d</strong>.</p><p>Please investigate immediately.</p>',
                $this->firstFailedId
            ),
        );
    }
}
