<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Host;
use App\Services\Hypervisor\HypervisorGateway;
use Illuminate\Console\Command;
use Throwable;

final class BackfillHostPinsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hosts:backfill-pins {--force : Overwrite existing pins}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Compute and backfill server_public_key_pin for existing hosts from server_certificate';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $query = Host::whereNotNull('server_certificate')
            ->where('server_certificate', '!=', '');

        if (! $force) {
            $query->where(function ($q) {
                $q->whereNull('server_public_key_pin')
                    ->orWhere('server_public_key_pin', '');
            });
        }

        $hosts = $query->get();

        if ($hosts->isEmpty()) {
            $this->info('No hosts found requiring public key pin backfill.');

            return self::SUCCESS;
        }

        $updated = 0;
        $failed = 0;

        foreach ($hosts as $host) {
            try {
                $pin = HypervisorGateway::computePinFromCertificate((string) $host->server_certificate);
                $host->server_public_key_pin = $pin;
                $host->save();

                $this->line(sprintf('Backfilled pin for host [%s] (ID: %d): %s', $host->name, $host->id, $pin));
                $updated++;
            } catch (Throwable $e) {
                $this->error(sprintf('Failed to compute pin for host [%s] (ID: %d): %s', $host->name, $host->id, $e->getMessage()));
                $failed++;
            }
        }

        $this->info(sprintf('Backfill completed: %d updated, %d failed.', $updated, $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
