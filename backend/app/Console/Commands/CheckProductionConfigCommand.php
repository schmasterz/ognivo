<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DTO\Cast;
use Illuminate\Console\Command;
use Illuminate\Support\Env;

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

        // 9. REVERB_APP_KEY check: must not be empty or unsafe default
        $reverbAppKey = Cast::string(config('reverb.apps.apps.0.key') ?? Env::get('REVERB_APP_KEY', ''));
        if ($reverbAppKey === '' || $reverbAppKey === 'null') {
            $violations[] = 'REVERB_APP_KEY is empty. A valid Reverb app key is required in production.';
        } elseif ($reverbAppKey === 'CHANGE_ME' || $reverbAppKey === 'secret_password') {
            $violations[] = "REVERB_APP_KEY is set to an unsafe default ('{$reverbAppKey}').";
        }

        // 10. AUDIT_DB_PASSWORD check: must not be empty or unsafe default
        $auditDbPassword = Cast::string(config('database.connections.audit.password') ?? Env::get('AUDIT_DB_PASSWORD', ''));
        if ($auditDbPassword === '' || $auditDbPassword === 'null') {
            $violations[] = 'AUDIT_DB_PASSWORD is empty. A strong audit database password is required in production.';
        } elseif ($auditDbPassword === 'secret_password' || $auditDbPassword === 'CHANGE_ME') {
            $violations[] = "AUDIT_DB_PASSWORD is set to an unsafe default ('{$auditDbPassword}').";
        }

        // 11. AUDIT_MAINT_DB_PASSWORD check: must not be empty or unsafe default
        $auditMaintDbPassword = Cast::string(config('database.connections.audit_maintenance.password') ?? Env::get('AUDIT_MAINT_DB_PASSWORD', ''));
        if ($auditMaintDbPassword === '' || $auditMaintDbPassword === 'null') {
            $violations[] = 'AUDIT_MAINT_DB_PASSWORD is empty. A strong audit maintenance database password is required in production.';
        } elseif ($auditMaintDbPassword === 'secret_password' || $auditMaintDbPassword === 'CHANGE_ME') {
            $violations[] = "AUDIT_MAINT_DB_PASSWORD is set to an unsafe default ('{$auditMaintDbPassword}').";
        }

        // 12. DB_MIGRATION_PASSWORD check: must not be empty or unsafe default
        $dbMigrationPassword = Cast::string(config('database.connections.migration.password') ?? Env::get('DB_MIGRATION_PASSWORD', ''));
        if ($dbMigrationPassword === '' || $dbMigrationPassword === 'null') {
            $violations[] = 'DB_MIGRATION_PASSWORD is empty. A strong migration database password is required in production.';
        } elseif ($dbMigrationPassword === 'secret_password' || $dbMigrationPassword === 'CHANGE_ME') {
            $violations[] = "DB_MIGRATION_PASSWORD is set to an unsafe default ('{$dbMigrationPassword}').";
        }

        // 13. AUDIT_HMAC_KEY check: must not be empty or unsafe default
        $auditHmacKeyId = Cast::string(config('audit.hmac_key_id') ?? config('audit.hmac.current_key_id') ?? 'v1', 'v1');
        $auditHmacKey = Cast::string(
            config('audit.hmac_keys.'.$auditHmacKeyId)
            ?? config('audit.hmac.keys.'.$auditHmacKeyId)
            ?? config('audit.hmac.current_key')
            ?? Env::get('AUDIT_HMAC_KEY', '')
        );
        if ($auditHmacKey === '' || $auditHmacKey === 'null') {
            $violations[] = 'AUDIT_HMAC_KEY is empty. A valid HMAC sealing key is required in production.';
        } elseif ($auditHmacKey === 'CHANGE_ME' || $auditHmacKey === 'secret_password') {
            $violations[] = "AUDIT_HMAC_KEY is set to an unsafe default ('{$auditHmacKey}').";
        }

        // 14. AUDIT_PSEUDONYM_SALT check: must not be empty or unsafe default
        $auditPseudonymSalt = Cast::string(config('audit.pseudonym_salt') ?? Env::get('AUDIT_PSEUDONYM_SALT', ''));
        if ($auditPseudonymSalt === '' || $auditPseudonymSalt === 'null') {
            $violations[] = 'AUDIT_PSEUDONYM_SALT is empty. A valid pseudonymisation salt is required in production.';
        } elseif ($auditPseudonymSalt === 'CHANGE_ME' || $auditPseudonymSalt === 'secret_password') {
            $violations[] = "AUDIT_PSEUDONYM_SALT is set to an unsafe default ('{$auditPseudonymSalt}').";
        }

        // 15. ALLOW_FAKE_DRIVER check: must be disabled in production
        $envAllowFakeDriver = Env::get('ALLOW_FAKE_DRIVER', false);
        if (
            config('app.allow_fake_driver') === true
            || filter_var($envAllowFakeDriver, FILTER_VALIDATE_BOOLEAN) === true
            || $envAllowFakeDriver === 'true'
            || $envAllowFakeDriver === '1'
        ) {
            $violations[] = 'ALLOW_FAKE_DRIVER is enabled. The fake hypervisor driver must be disabled in production.';
        }

        // 16. AUDIT_STRICT_GRANTS check: must be enabled in production
        $strictGrants = config('audit.strict_grants');
        $envStrictGrants = Env::get('AUDIT_STRICT_GRANTS');
        if ($strictGrants !== true || $envStrictGrants === 'false' || $envStrictGrants === '0') {
            $violations[] = 'AUDIT_STRICT_GRANTS is disabled. Strict audit database privilege separation must be enabled in production.';
        }

        // 17. SESSION_SECURE_COOKIE check: must be enabled in production
        $sessionSecure = config('session.secure');
        $envSessionSecure = Env::get('SESSION_SECURE_COOKIE');
        if (
            filter_var($sessionSecure ?? $envSessionSecure, FILTER_VALIDATE_BOOLEAN) !== true
            || $envSessionSecure === 'false'
            || $envSessionSecure === '0'
        ) {
            $violations[] = 'SESSION_SECURE_COOKIE is disabled or unset. Session cookies must be restricted to HTTPS in production.';
        }

        // 18. APP_URL check: must be a valid HTTPS URL pointing to a public hostname
        $appUrl = trim(Cast::string(config('app.url') ?? Env::get('APP_URL', '')));
        $parsedUrl = parse_url($appUrl);
        $scheme = is_array($parsedUrl) ? strtolower(Cast::string($parsedUrl['scheme'] ?? '')) : '';
        $host = is_array($parsedUrl) ? strtolower(trim(Cast::string($parsedUrl['host'] ?? ''), '[]')) : '';

        if ($appUrl === '' || $parsedUrl === false || $scheme === '' || $host === '') {
            $violations[] = "APP_URL is empty or invalid ('{$appUrl}'). A valid HTTPS production URL is required.";
        } else {
            if ($scheme !== 'https') {
                $violations[] = "APP_URL must use HTTPS in production ('{$appUrl}').";
            }

            if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
                $violations[] = "APP_URL points to a local/loopback address ('{$appUrl}'). A public production hostname is required.";
            }
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
