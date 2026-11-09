<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Host;
use App\Services\Hypervisor\Contracts\IncusEventTransportInterface;
use App\Services\Hypervisor\IncusEventSyncService;
use App\Services\Hypervisor\Transports\FakeIncusEventTransport;
use Illuminate\Console\Command;

/**
 * Artisan command to listen for Incus lifecycle events via WebSocket and synchronize VM states.
 */
class IncusListenCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'incus:listen {host? : Host ID or name to listen to}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Listen to Incus lifecycle events over WebSocket and synchronize VM states with broadcasts';

    protected bool $shouldStop = false;

    public function __construct(
        protected readonly IncusEventSyncService $syncService,
        protected readonly IncusEventTransportInterface $defaultTransport,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function (): void {
                $this->shouldStop = true;
                $this->info('Received termination signal. Shutting down gracefully...');
            });
            pcntl_signal(SIGINT, function (): void {
                $this->shouldStop = true;
                $this->info('Received interrupt signal. Shutting down gracefully...');
            });
        }

        $hostArg = $this->argument('host');

        if ($hostArg !== null && $hostArg !== '') {
            /** @var Host|null $host */
            $host = Host::query()
                ->where('id', $hostArg)
                ->orWhere('name', $hostArg)
                ->first();

            if ($host === null) {
                $this->error(sprintf('Host node [%s] not found.', $hostArg));

                return self::FAILURE;
            }

            $this->listenToHost($host);

            return self::SUCCESS;
        }

        // No host provided: continuously listen to all active hosts
        while (! $this->shouldStop) {
            $hosts = Host::query()
                ->where('is_active', true)
                ->whereIn('driver', ['incus', 'lxd', 'fake'])
                ->get();

            if ($hosts->isEmpty()) {
                $this->warn('No active Incus/LXD host nodes found. Waiting for hosts to be added...');
                sleep(2);

                continue;
            }

            foreach ($hosts as $host) {
                if ($this->shouldStop) {
                    break;
                }
                $this->listenToHost($host);
            }

            // In daemon mode, sleep briefly to prevent tight spin when transports return immediately
            sleep(1);
        }

        return self::SUCCESS;
    }

    /**
     * Listen to a specific host node.
     */
    protected function listenToHost(Host $host): void
    {
        $this->info(sprintf('Starting event listener for host [%s] (%s)...', $host->name, $host->endpoint_url));

        // Use fake transport if driver is fake or in testing
        $transport = $host->driver === 'fake' || app()->runningUnitTests()
            ? app(FakeIncusEventTransport::class)
            : $this->defaultTransport;

        $transport->listen(
            $host,
            function (array $event) use ($host): void {
                $this->line(sprintf('Received event: %s', json_encode($event)));
                $vm = $this->syncService->handleLifecycleEvent($host, $event);
                if ($vm !== null) {
                    $this->info(sprintf('Updated VM [%s] -> Status [%s]', $vm->name, $vm->status->getValue()));
                }
            },
            fn (): bool => $this->shouldStop
        );
    }
}
