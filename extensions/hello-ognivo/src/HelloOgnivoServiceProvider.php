<?php

declare(strict_types=1);

namespace Ognivo\HelloOgnivo;

use App\Contracts\DnsProviderInterface;
use App\Events\Instance\InstanceCreated;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Ognivo\HelloOgnivo\Listeners\LogInstanceCreationListener;
use Override;

/**
 * Service provider for the Hello-Ognivo reference extension.
 */
final class HelloOgnivoServiceProvider extends ServiceProvider
{
    /**
     * Register extension bindings in the application container.
     */
    #[Override]
    public function register(): void
    {
        $this->app->singleton(
            DnsProviderInterface::class,
            static fn (): DnsProviderInterface => new class implements DnsProviderInterface
            {
                /**
                 * @var array<string, list<array{id: string, name: string, type: string, content: string, ttl: int}>>
                 */
                private array $records = [];

                #[Override]
                public function createRecord(string $zone, string $name, string $type, string $content, int $ttl = 300): bool
                {
                    $this->records[$zone][] = [
                        'id' => 'hello-ognivo-'.(count($this->records[$zone] ?? []) + 1),
                        'name' => $name,
                        'type' => $type,
                        'content' => $content,
                        'ttl' => $ttl,
                    ];

                    Log::info('HelloOgnivo: Mock DNS record created.', [
                        'zone' => $zone,
                        'name' => $name,
                        'type' => $type,
                        'content' => $content,
                        'ttl' => $ttl,
                    ]);

                    return true;
                }

                #[Override]
                public function deleteRecord(string $zone, string $recordId): bool
                {
                    if (! isset($this->records[$zone])) {
                        return false;
                    }

                    $initialCount = count($this->records[$zone]);
                    $this->records[$zone] = array_values(array_filter(
                        $this->records[$zone],
                        static fn (array $record): bool => $record['id'] !== $recordId,
                    ));

                    return count($this->records[$zone]) < $initialCount;
                }

                /**
                 * @return list<array{id: string, name: string, type: string, content: string, ttl: int}>
                 */
                #[Override]
                public function listRecords(string $zone): array
                {
                    return $this->records[$zone] ?? [];
                }
            }
        );
    }

    /**
     * Bootstrap extension event listeners.
     */
    public function boot(): void
    {
        Event::listen(InstanceCreated::class, LogInstanceCreationListener::class);
    }
}
