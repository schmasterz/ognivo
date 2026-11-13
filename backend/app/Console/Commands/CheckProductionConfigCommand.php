<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class CheckProductionConfigCommand extends Command
{
    /**
     * The previously committed APP_KEY that must be rejected in production.
     */
    public const COMMITTED_APP_KEY = 'base64:Y1Odc8L0LjquB4i+2b5IKHGXb0gx1mhGPUklvYCJ+bU=';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:check-production-config';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify that production configuration does not use unsafe defaults or compromised keys';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $violations = [];

        // 1. APP_KEY check
        $appKey = (string) (config('app.key') ?? env('APP_KEY', ''));
        if ($appKey === '') {
            $violations[] = 'APP_KEY is empty. A valid 32-byte encryption key is required.';
        } elseif ($appKey === self::COMMITTED_APP_KEY) {
            $violations[] = 'APP_KEY matches the publicly compromised key previously committed to the repository.';
        } elseif ($appKey === 'CHANGE_ME') {
            $violations[] = "APP_KEY is set to placeholder 'CHANGE_ME'.";
        }

        // 2. DB_PASSWORD check
        $dbPassword = (string) (config('database.connections.mysql.password') ?? env('DB_PASSWORD', ''));
        if ($dbPassword === 'secret_password' || $dbPassword === 'CHANGE_ME') {
            $violations[] = "DB_PASSWORD is set to an unsafe default ('{$dbPassword}').";
        }

        // 3. DB_ROOT_PASSWORD check
        $dbRootPassword = (string) (config('database.connections.mysql.root_password') ?? env('DB_ROOT_PASSWORD', ''));
        if ($dbRootPassword === 'secret_password' || $dbRootPassword === 'CHANGE_ME') {
            $violations[] = "DB_ROOT_PASSWORD is set to an unsafe default ('{$dbRootPassword}').";
        }

        // 4. REVERB_APP_SECRET check
        $reverbSecret = (string) (config('reverb.apps.apps.0.secret') ?? env('REVERB_APP_SECRET', ''));
        if ($reverbSecret === 'reverb_secret_key' || $reverbSecret === 'CHANGE_ME') {
            $violations[] = "REVERB_APP_SECRET is set to an unsafe default ('{$reverbSecret}').";
        }

        // 5. REVERB_ALLOWED_ORIGINS check: must not contain wildcard '*'
        $configOrigins = config('reverb.apps.apps.0.allowed_origins');
        $envOrigins = env('REVERB_ALLOWED_ORIGINS');

        $configOriginsString = is_array($configOrigins) ? implode(',', $configOrigins) : (string) ($configOrigins ?? '');
        $envOriginsString = (string) ($envOrigins ?? '');

        if (str_contains($envOriginsString, '*') || str_contains($configOriginsString, '*')) {
            $badOrigins = str_contains($envOriginsString, '*') ? $envOriginsString : $configOriginsString;
            $violations[] = "REVERB_ALLOWED_ORIGINS contains wildcard '*' ('{$badOrigins}'). Wildcard origins are forbidden in production.";
        }

        // 6. APP_DEBUG check: must be false
        $appDebug = config('app.debug') ?? filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN);
        if ($appDebug === true || env('APP_DEBUG') === 'true' || env('APP_DEBUG') === '1') {
            $violations[] = 'APP_DEBUG is enabled. Debug mode must be disabled in production.';
        }

        // 7. RUN_SEEDERS check: must not be true in production
        $runSeeders = config('app.run_seeders') ?? filter_var(env('RUN_SEEDERS', false), FILTER_VALIDATE_BOOLEAN);
        if ($runSeeders === true || env('RUN_SEEDERS') === 'true' || env('RUN_SEEDERS') === '1') {
            $violations[] = 'RUN_SEEDERS is enabled. Database seeders must not run automatically in production.';
        }

        // 8. REDIS_PASSWORD check: must not be empty or unsafe default
        $redisPassword = (string) (config('database.redis.default.password') ?? env('REDIS_PASSWORD', ''));
        if ($redisPassword === '' || $redisPassword === 'null') {
            $violations[] = 'REDIS_PASSWORD is empty. A strong Redis password is required in production.';
        } elseif ($redisPassword === 'secret_password' || $redisPassword === 'CHANGE_ME') {
            $violations[] = "REDIS_PASSWORD is set to an unsafe default ('{$redisPassword}').";
        }

        if (! empty($violations)) {
            $this->error('================================================================');
            $this->error(' PRODUCTION CONFIGURATION CHECK FAILED: UNSAFE DEFAULTS DETECTED');
            $this->error('================================================================');
            foreach ($violations as $index => $violation) {
                $this->error(sprintf(' [%d] %s', $index + 1, $violation));
            }
            $this->error('================================================================');
            $this->error('Please update your .env.production file before starting the application.');

            return self::FAILURE;
        }

        $this->info('Production configuration check passed: all security checks verified.');

        return self::SUCCESS;
    }
}
