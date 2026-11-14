<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Host;
use App\Services\Hypervisor\Contracts\IncusEventTransportInterface;
use App\Services\Hypervisor\IncusEventSyncService;
use App\Services\Hypervisor\Transports\FakeIncusEventTransport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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
    protected $signature = 'incus:listen {host? : Host ID or name to listen to} {--once : Run one iteration across active hosts and exit}';

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
        $this->setupSignalHandlers();

        $hostArg = $this->argument('host');

        if (is_string($hostArg) && $hostArg !== '') {
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

        // Test mode, one-off execution, or missing pcntl_fork extension
        if (app()->runningUnitTests() || (bool) $this->option('once') || ! function_exists('pcntl_fork')) {
            return $this->runSequentialListener();
        }

        return $this->runSupervisor();
    }

    /**
     * Setup graceful termination signal handlers for the process.
     */
    protected function setupSignalHandlers(): void
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
    }

    /**
     * Run sequential listener across active hosts (used in tests, one-off runs, or when pcntl is unavailable).
     */
    protected function runSequentialListener(): int
    {
        $isOnce = app()->runningUnitTests() || (bool) $this->option('once');

        while (! $this->shouldStop) {
            $hosts = Host::query()
                ->where('is_active', true)
                ->whereIn('driver', ['incus', 'lxd', 'fake'])
                ->get();

            if ($hosts->isEmpty()) {
                if ($isOnce) {
                    $this->warn('No active Incus/LXD host nodes found.');

                    return self::SUCCESS;
                }

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

            if ($isOnce) {
                break;
            }

            // In daemon mode, sleep briefly to prevent tight spin when transports return immediately
            sleep(1);
        }

        return self::SUCCESS;
    }

    /**
     * Run multi-process supervisor concurrently listening to all active hosts.
     */
    protected function runSupervisor(): int
    {
        $this->info('Starting Incus listener supervisor for active hosts...');

        /** @var array<int, int> $workers Host ID => PID */
        $workers = [];
        $lastPoll = 0;
        $pollInterval = 15; // seconds

        while (! $this->shouldStop) {
            // Reap any exited child processes
            $this->reapChildren($workers);

            $now = time();
            if ($now - $lastPoll >= $pollInterval) {
                $lastPoll = $now;
                $this->syncHostWorkers($workers);
            }

            sleep(1);
        }

        $this->info('Supervisor shutting down. Terminating all host workers...');
        $this->terminateAllWorkers($workers);
        $this->info('All host workers terminated. Supervisor exit.');

        return self::SUCCESS;
    }

    /**
     * Synchronize active hosts with running worker child processes.
     *
     * @param  array<int, int>  $workers  Host ID => PID
     */
    protected function syncHostWorkers(array &$workers): void
    {
        try {
            $activeHosts = Host::query()
                ->where('is_active', true)
                ->whereIn('driver', ['incus', 'lxd', 'fake'])
                ->get();
        } catch (\Throwable $e) {
            $this->error(sprintf('Failed to query active hosts: %s', $e->getMessage()));

            return;
        }

        /** @var list<int> $activeHostIds */
        $activeHostIds = $activeHosts->pluck('id')->all();

        // 1. Terminate workers for hosts that are no longer active or have been deleted
        foreach ($workers as $hostId => $pid) {
            if (! in_array($hostId, $activeHostIds, true)) {
                $this->info(sprintf('Host ID [%d] is no longer active. Sending SIGTERM to worker PID %d...', $hostId, $pid));
                if (function_exists('posix_kill')) {
                    posix_kill($pid, SIGTERM);
                }
            }
        }

        // 2. Spawn worker processes for active hosts without a running worker
        foreach ($activeHosts as $host) {
            if (! isset($workers[$host->id])) {
                $pid = $this->spawnWorker($host);
                if ($pid > 0) {
                    $workers[$host->id] = $pid;
                }
            }
        }
    }

    /**
     * Spawn a child worker process for a host.
     */
    protected function spawnWorker(Host $host): int
    {
        if (! function_exists('pcntl_fork')) {
            return -1;
        }

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->error(sprintf('Failed to fork worker process for host [%s] (ID %d).', $host->name, $host->id));

            return -1;
        }

        if ($pid === 0) {
            // Inside child process
            $this->runChildWorker($host);
            exit(0);
        }

        // Inside parent process
        $this->info(sprintf('Spawned worker PID %d for host [%s] (ID %d).', $pid, $host->name, $host->id));

        return $pid;
    }

    /**
     * Entry point for a child worker process listening to a single host.
     */
    protected function runChildWorker(Host $host): void
    {
        // Disconnect DB connection inherited from parent to ensure independent PDO connection
        DB::purge();

        $childShouldStop = false;

        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function () use (&$childShouldStop): void {
                $childShouldStop = true;
            });
            pcntl_signal(SIGINT, function () use (&$childShouldStop): void {
                $childShouldStop = true;
            });
        }

        try {
            $this->listenToHost($host, fn (): bool => $childShouldStop);
        } catch (\Throwable $e) {
            $this->error(sprintf('Worker for host [%s] encountered an unhandled exception: %s', $host->name, $e->getMessage()));
            exit(1);
        }
    }

    /**
     * Reap any exited child processes without blocking.
     *
     * @param  array<int, int>  $workers  Host ID => PID
     */
    protected function reapChildren(array &$workers): void
    {
        if (! function_exists('pcntl_waitpid')) {
            return;
        }

        foreach ($workers as $hostId => $pid) {
            $status = 0;
            $res = pcntl_waitpid($pid, $status, WNOHANG);
            if ($res === $pid || $res === -1) {
                $this->warn(sprintf('Worker process for host ID [%d] (PID %d) has exited.', $hostId, $pid));
                unset($workers[$hostId]);
            }
        }
    }

    /**
     * Terminate all worker processes gracefully on parent shutdown.
     *
     * @param  array<int, int>  $workers  Host ID => PID
     */
    protected function terminateAllWorkers(array $workers): void
    {
        if ($workers === []) {
            return;
        }

        // 1. Send SIGTERM to all workers
        foreach ($workers as $hostId => $pid) {
            $this->info(sprintf('Sending SIGTERM to worker PID %d (host ID %d)...', $pid, $hostId));
            if (function_exists('posix_kill')) {
                posix_kill($pid, SIGTERM);
            }
        }

        // 2. Wait up to 5 seconds for workers to exit cleanly
        $deadline = time() + 5;
        while (true) {
            foreach ($workers as $hostId => $pid) {
                $status = 0;
                $res = function_exists('pcntl_waitpid')
                    ? pcntl_waitpid($pid, $status, WNOHANG)
                    : -1;

                if ($res === $pid || $res === -1) {
                    unset($workers[$hostId]);
                }
            }

            if ($workers === []) {
                break;
            }

            if (time() >= $deadline) {
                // Force kill remaining workers
                foreach ($workers as $hostId => $pid) {
                    $this->warn(sprintf('Worker PID %d did not terminate gracefully; sending SIGKILL...', $pid));
                    if (function_exists('posix_kill')) {
                        posix_kill($pid, SIGKILL);
                    }
                    $status = 0;
                    if (function_exists('pcntl_waitpid')) {
                        pcntl_waitpid($pid, $status);
                    }
                    unset($workers[$hostId]);
                }
                break;
            }

            usleep(100000); // 100ms
        }
    }

    /**
     * Listen to a specific host node.
     *
     * @param  (callable(): bool)|null  $shouldStop
     */
    protected function listenToHost(Host $host, ?callable $shouldStop = null): void
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
            $shouldStop ?? fn (): bool => $this->shouldStop
        );
    }
}
