<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Contract for pluggable notification delivery channels (Developer Preview).
 *
 * @experimental This extension contract is in Developer Preview and may evolve in minor 1.x releases based on feedback from real reference implementations. It will be frozen under full SemVer once proven with core-integrated modules.
 */
interface NotificationChannelInterface
{
    /**
     * Unique identifier for this channel (e.g. "slack", "discord", "email").
     */
    public function getIdentifier(): string;

    /**
     * Check whether the channel is configured and enabled.
     */
    public function isConfigured(): bool;

    /**
     * Send a notification message through this channel.
     *
     * @param  string  $recipient  Destination identifier (email, phone, channel ID, webhook URL).
     * @param  string  $title  Short title / subject of the notification.
     * @param  string  $message  Main message body text or markdown.
     * @param  array<string, mixed>  $metadata  Extra contextual metadata.
     * @return bool True if delivered or accepted by provider.
     */
    public function send(string $recipient, string $title, string $message, array $metadata = []): bool;
}
