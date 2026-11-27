<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\BackupTargetInterface;
use App\Contracts\BillingProviderInterface;
use App\Contracts\DnsProviderInterface;
use App\Contracts\NotificationChannelInterface;
use App\Contracts\VpsPlanChangeAuthorizerInterface;
use App\Contracts\VpsProvisioningAuthorizerInterface;
use App\Listeners\WebhookEventSubscriber;
use App\Services\Backup\LocalSnapshotTarget;
use App\Services\Vps\AllowAllVpsPlanChangeAuthorizer;
use App\Services\Vps\AllowAllVpsProvisioningAuthorizer;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Registers default fallback container bindings for pluggable extension contracts.
 */
final class ExtensionServiceProvider extends ServiceProvider
{
    /**
     * Register default extension contract bindings if not already bound.
     */
    #[Override]
    public function register(): void
    {
        if (! $this->app->bound(DnsProviderInterface::class)) {
            $this->app->bind(
                DnsProviderInterface::class,
                static fn (): DnsProviderInterface => new class implements DnsProviderInterface
                {
                    #[Override]
                    public function createRecord(string $zone, string $name, string $type, string $content, int $ttl = 300): bool
                    {
                        return false;
                    }

                    #[Override]
                    public function deleteRecord(string $zone, string $recordId): bool
                    {
                        return false;
                    }

                    /**
                     * @return list<array{id: string, name: string, type: string, content: string, ttl: int}>
                     */
                    #[Override]
                    public function listRecords(string $zone): array
                    {
                        return [];
                    }
                }
            );
        }

        if (! $this->app->bound(NotificationChannelInterface::class)) {
            $this->app->bind(
                NotificationChannelInterface::class,
                static fn (): NotificationChannelInterface => new class implements NotificationChannelInterface
                {
                    #[Override]
                    public function getIdentifier(): string
                    {
                        return 'log';
                    }

                    #[Override]
                    public function isConfigured(): bool
                    {
                        return true;
                    }

                    /**
                     * @param  array<string, mixed>  $metadata
                     */
                    #[Override]
                    public function send(string $recipient, string $title, string $message, array $metadata = []): bool
                    {
                        Log::info('Notification dispatched via default log channel.', [
                            'recipient' => $recipient,
                            'title' => $title,
                            'message' => $message,
                            'metadata' => $metadata,
                        ]);

                        return true;
                    }
                }
            );
        }

        if (! $this->app->bound(BillingProviderInterface::class)) {
            $this->app->bind(
                BillingProviderInterface::class,
                static fn (): BillingProviderInterface => new class implements BillingProviderInterface
                {
                    #[Override]
                    public function getIdentifier(): string
                    {
                        return 'null';
                    }

                    /**
                     * @param  array<string, mixed>  $tags
                     */
                    #[Override]
                    public function recordUsage(int $userId, string $metric, float $quantity, array $tags = []): void
                    {
                        // No-op null billing usage recorder.
                    }
                }
            );
        }

        if (! $this->app->bound(BackupTargetInterface::class)) {
            $this->app->bind(BackupTargetInterface::class, LocalSnapshotTarget::class);
        }

        if (! $this->app->bound(VpsPlanChangeAuthorizerInterface::class)) {
            $this->app->bind(VpsPlanChangeAuthorizerInterface::class, AllowAllVpsPlanChangeAuthorizer::class);
        }

        if (! $this->app->bound(VpsProvisioningAuthorizerInterface::class)) {
            $this->app->bind(VpsProvisioningAuthorizerInterface::class, AllowAllVpsProvisioningAuthorizer::class);
        }
    }

    /**
     * Bootstrap extension event subscribers.
     */
    public function boot(): void
    {
        Event::subscribe(WebhookEventSubscriber::class);
    }
}
