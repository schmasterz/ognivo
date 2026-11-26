<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DTO\Cast;
use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Console command to purge all legacy database-backed Sanctum personal access tokens.
 *
 * This is a one-time deploy step for the browser cookie-authentication migration
 * and can be removed in a later release.
 */
final class PurgeLegacyAuthTokensCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'auth:purge-legacy-tokens {--dry-run : Only count tokens without deleting} {--force : Skip the confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete all legacy database-backed Sanctum personal access tokens';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $count = PersonalAccessToken::query()->count();
        $this->info("Found {$count} legacy personal access token(s) in personal_access_tokens.");

        if ($dryRun) {
            $this->info('Dry-run mode: no tokens were deleted.');

            return self::SUCCESS;
        }

        if (! $force) {
            if (! $this->input->isInteractive()) {
                $this->error('Non-interactive session detected. Re-run with --force to delete all legacy personal access tokens.');

                return self::FAILURE;
            }

            $confirmed = $this->confirm(
                'Are you sure you want to delete all legacy personal access tokens? This action cannot be undone.',
                false
            );

            if (! $confirmed) {
                $this->warn('Aborted: no legacy personal access tokens were deleted.');

                return self::FAILURE;
            }
        }

        $deleted = Cast::int(PersonalAccessToken::query()->delete());
        $this->info("Deleted {$deleted} legacy personal access token(s).");

        return self::SUCCESS;
    }
}
