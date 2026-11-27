<?php

declare(strict_types=1);

namespace App\Mail;

use App\Support\Cast;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class SmtpTestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $serverHost,
        public readonly int $serverPort,
        public readonly string $fromEmail,
        public readonly string $fromSenderName,
    ) {}

    public function envelope(): Envelope
    {
        $appName = Cast::string(config('app.name', 'Ognivo'), 'Ognivo');

        return new Envelope(
            from: new Address($this->fromEmail, $this->fromSenderName),
            subject: sprintf('%s - SMTP Configuration Test', $appName),
        );
    }

    public function content(): Content
    {
        $appName = Cast::string(config('app.name', 'Ognivo'), 'Ognivo');

        return new Content(
            htmlString: sprintf(
                '<h2>SMTP Configuration Test</h2><p>This is a verification email sent from <strong>%s</strong>.</p><p><strong>Server Host:</strong> %s:%d</p><p><strong>Timestamp:</strong> %s</p><p>If you received this message, your outgoing mail configuration is operating successfully!</p>',
                htmlspecialchars($appName),
                htmlspecialchars($this->serverHost),
                $this->serverPort,
                now()->toIso8601String()
            ),
        );
    }
}
