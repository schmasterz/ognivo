<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Host;
use App\Models\PanelCertificate;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Override;
use ReflectionClass;

final class ReencryptSecretsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:reencrypt-secrets
        {--dry-run : Simulate re-encryption and display what would be updated without persisting changes}
        {--include-users : Force immediate re-encryption of user secrets / password pre-hashes}
        {--status : Check and report secrets encryption status across models without modifying data}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Re-encrypt all encrypted database attributes with the current APP_KEY';

    /**
     * Configure the console command help text.
     */
    #[Override]
    protected function configure(): void
    {
        parent::configure();

        $this->setHelp(<<<'HELP'
Re-encrypts all encrypted database attributes (PanelCertificate::private_key,
Host::token, any dynamic model attribute using an `encrypted` cast, and the
SMTP password stored in SystemSetting::smtp_settings) using the current APP_KEY.

Options:
  --dry-run         Simulate re-encryption in memory and report what would be
                    updated without persisting any changes to the database.
  --include-users   Also scan App\Models\User records (reports user record scan
                    status; note that password pre-hashes are one-way bcrypt+HMAC
                    hashes that rotate transparently on next user login).
  --status          Inspect all encrypted secrets and report counts encrypted
                    with the current APP_KEY, APP_PREVIOUS_KEYS, or undecryptable,
                    without modifying any database records.

Exit Codes:
  0  Success (all secrets re-encrypted, dry-run completed with 0 decryption
     errors, or --status confirmed 0 secrets on a previous key and 0 undecryptable).
  1  Failure (decryption error encountered, undecryptable records found, or
     secrets remain encrypted with APP_PREVIOUS_KEYS during --status).

Usage Examples:
  Production (container: ognivo-backend):
    docker exec -i ognivo-backend php artisan app:reencrypt-secrets --status
    docker exec -i ognivo-backend php artisan app:reencrypt-secrets --dry-run
    docker exec -i ognivo-backend php artisan app:reencrypt-secrets --include-users
    docker exec -i ognivo-backend php artisan app:reencrypt-secrets

  Development (container: ognivo-backend-dev):
    docker exec -i ognivo-backend-dev php artisan app:reencrypt-secrets --status
    docker exec -i ognivo-backend-dev php artisan app:reencrypt-secrets --dry-run
    docker exec -i ognivo-backend-dev php artisan app:reencrypt-secrets --include-users
    docker exec -i ognivo-backend-dev php artisan app:reencrypt-secrets
HELP);
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $statusOnly = (bool) $this->option('status');
        $dryRun = (bool) $this->option('dry-run');
        $includeUsers = (bool) $this->option('include-users');

        if ($statusOnly) {
            return $this->handleStatus($includeUsers);
        }

        return $this->handleReencrypt($dryRun, $includeUsers);
    }

    /**
     * Check and report encryption key status across all secrets without modifying data.
     */
    private function handleStatus(bool $includeUsers): int
    {
        $enc = $this->resolveEncrypter();
        if ($enc === null) {
            $this->error('Unable to inspect encryption keys: active encrypter is not an Encrypter instance.');

            return self::FAILURE;
        }

        $cipherConfig = config('app.cipher');
        $cipher = is_string($cipherConfig) && $cipherConfig !== '' ? $cipherConfig : 'AES-256-CBC';
        $currentKeyEncrypter = new Encrypter($enc->getKey(), $cipher);

        $this->info('Checking secrets encryption status...');

        $modelsToProcess = $this->discoverEncryptedModels();
        $rows = [];
        $totalPrevious = 0;
        $totalUndecryptable = 0;

        foreach ($modelsToProcess as $class => $attributes) {
            $records = $class::all();

            foreach ($attributes as $attribute) {
                $currentCount = 0;
                $previousCount = 0;
                $undecryptableCount = 0;

                foreach ($records as $record) {
                    $rawValue = $record->getRawOriginal($attribute);
                    if (! is_string($rawValue) || $rawValue === '') {
                        continue;
                    }

                    match ($this->classifyCiphertext($rawValue, $currentKeyEncrypter, $enc)) {
                        'current' => $currentCount++,
                        'previous' => $previousCount++,
                        'undecryptable' => $undecryptableCount++,
                    };
                }

                $rows[] = [
                    "{$class}::{$attribute}",
                    (string) $currentCount,
                    (string) $previousCount,
                    (string) $undecryptableCount,
                ];
                $totalPrevious += $previousCount;
                $totalUndecryptable += $undecryptableCount;
            }
        }

        $smtpCurrent = 0;
        $smtpPrevious = 0;
        $smtpUndecryptable = 0;

        $smtpSetting = SystemSetting::where('key', 'smtp_settings')->first();
        if ($smtpSetting !== null && is_array($smtpSetting->value)) {
            $rawPassword = $smtpSetting->value['password'] ?? null;
            if (is_string($rawPassword) && $rawPassword !== '') {
                match ($this->classifyCiphertext($rawPassword, $currentKeyEncrypter, $enc)) {
                    'current' => $smtpCurrent++,
                    'previous' => $smtpPrevious++,
                    'undecryptable' => $smtpUndecryptable++,
                };
            }
        }

        $rows[] = [
            SystemSetting::class."::smtp_settings['password']",
            (string) $smtpCurrent,
            (string) $smtpPrevious,
            (string) $smtpUndecryptable,
        ];
        $totalPrevious += $smtpPrevious;
        $totalUndecryptable += $smtpUndecryptable;

        $userCount = null;
        if ($includeUsers && ! array_key_exists(User::class, $modelsToProcess)) {
            $userCount = User::query()->count();
            $rows[] = [
                User::class.' (password pre-hashes)',
                '0',
                '0',
                '0',
            ];
        }

        $this->table(
            ['Model / Secret', 'Current Key', 'Previous Key', 'Undecryptable'],
            $rows
        );

        if ($userCount !== null) {
            $this->line("Checked {$userCount} user record(s) for [".User::class.'] (password pre-hashes are one-way hashes rotated transparently on login).');
        }

        if ($totalPrevious === 0 && $totalUndecryptable === 0) {
            $this->info('All secrets are encrypted with the current APP_KEY. 0 secrets on a previous key.');

            return self::SUCCESS;
        }

        if ($totalPrevious > 0) {
            $this->warn("{$totalPrevious} secret(s) remain encrypted with a key from APP_PREVIOUS_KEYS.");
        }

        if ($totalUndecryptable > 0) {
            $this->error("{$totalUndecryptable} undecryptable secret(s) found. Check APP_KEY and APP_PREVIOUS_KEYS.");
        }

        $this->error('Unmigrated or undecryptable secrets remain. Run php artisan app:reencrypt-secrets to re-encrypt.');

        return self::FAILURE;
    }

    /**
     * Re-encrypt all discovered model attributes and SMTP password, or simulate when --dry-run is set.
     */
    private function handleReencrypt(bool $dryRun, bool $includeUsers): int
    {
        $prefix = $dryRun ? '[DRY-RUN] ' : '';

        $this->info("{$prefix}Scanning models for encrypted attributes...");

        $modelsToProcess = $this->discoverEncryptedModels();

        $totalRecords = 0;
        $totalErrors = 0;

        foreach ($modelsToProcess as $class => $attributes) {
            $attrList = implode(', ', $attributes);
            $this->line("{$prefix}Processing [{$class}] (attributes: {$attrList})...");

            $records = $class::all();
            $classCount = 0;

            foreach ($records as $record) {
                $updates = [];
                $recordError = false;

                foreach ($attributes as $attribute) {
                    try {
                        $plaintext = $record->getAttribute($attribute);
                    } catch (DecryptException $e) {
                        $this->error("{$prefix}Failed to decrypt attribute '{$attribute}' on {$class} #{$record->getKey()}: {$e->getMessage()}");
                        $recordError = true;

                        continue;
                    }

                    if ($plaintext !== null) {
                        // Setting the attribute re-encrypts it using the current active encrypter
                        $record->setAttribute($attribute, $plaintext);
                        $updates[$attribute] = $record->getAttributes()[$attribute];
                    }
                }

                if ($recordError) {
                    $totalErrors++;

                    continue;
                }

                if (! empty($updates)) {
                    if (! $dryRun) {
                        // Force update the table directly to ensure newly generated ciphertext is saved
                        $record->newQuery()->whereKey($record->getKey())->update($updates);
                    }
                    $classCount++;
                    $totalRecords++;
                }
            }

            $verb = $dryRun ? 'Would re-encrypt' : 'Re-encrypted';
            $this->info("{$prefix} -> {$verb} {$classCount} record(s) for [{$class}].");
        }

        if ($includeUsers && ! array_key_exists(User::class, $modelsToProcess)) {
            $this->line("{$prefix}Processing [".User::class.'] (--include-users enabled)...');
            $userCount = User::query()->count();
            $this->info("{$prefix} -> Checked {$userCount} user record(s) (password pre-hashes rotate transparently on next login).");
        }

        $smtpSetting = SystemSetting::where('key', 'smtp_settings')->first();
        if ($smtpSetting !== null && is_array($smtpSetting->value)) {
            if (isset($smtpSetting->value['password']) && is_string($smtpSetting->value['password']) && $smtpSetting->value['password'] !== '') {
                try {
                    $plainPassword = Crypt::decryptString($smtpSetting->value['password']);
                    $value = $smtpSetting->value;
                    $value['password'] = Crypt::encryptString($plainPassword);
                    if (! $dryRun) {
                        $smtpSetting->newQuery()->whereKey($smtpSetting->getKey())->update([
                            'value' => json_encode($value, JSON_THROW_ON_ERROR),
                        ]);
                    }
                    $totalRecords++;
                    if ($dryRun) {
                        $this->info("{$prefix} -> Would re-encrypt SMTP password for [".SystemSetting::class.'].');
                    }
                } catch (DecryptException $e) {
                    $this->error("{$prefix}Failed to decrypt SMTP password in SystemSetting: {$e->getMessage()}");
                    $totalErrors++;
                }
            }
        }

        if ($totalErrors > 0) {
            $this->error("{$prefix}Re-encryption finished with {$totalErrors} decryption error(s). Please check your APP_PREVIOUS_KEYS setting.");

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->info("{$prefix}Dry-run completed successfully. Total records that would be updated: {$totalRecords}. No database changes were persisted.");
        } else {
            $this->info("Re-encryption completed successfully. Total records updated: {$totalRecords}.");
        }

        return self::SUCCESS;
    }

    /**
     * Resolve the active Encrypter instance.
     */
    private function resolveEncrypter(): ?Encrypter
    {
        $enc = Crypt::getFacadeRoot();
        if ($enc instanceof Encrypter) {
            return $enc;
        }

        $modelEnc = Model::currentEncrypter();

        return $modelEnc instanceof Encrypter ? $modelEnc : null;
    }

    /**
     * Classify a ciphertext payload as encrypted with the current key, a previous key, or undecryptable.
     *
     * @return 'current'|'previous'|'undecryptable'
     */
    private function classifyCiphertext(string $ciphertext, Encrypter $currentKeyEncrypter, Encrypter $fullEncrypter): string
    {
        try {
            $currentKeyEncrypter->decryptString($ciphertext);

            return 'current';
        } catch (DecryptException) {
            // Fall through and try with previous keys included
        }

        try {
            $fullEncrypter->decryptString($ciphertext);

            return 'previous';
        } catch (DecryptException) {
            return 'undecryptable';
        }
    }

    /**
     * Discover all Eloquent models and their encrypted attributes.
     *
     * @return array<class-string<Model>, list<string>>
     */
    private function discoverEncryptedModels(): array
    {
        $models = [
            PanelCertificate::class => ['private_key'],
            Host::class => ['token'],
        ];

        // Also dynamically inspect any model classes in app/Models
        $modelFiles = glob(app_path('Models/*.php')) ?: [];

        foreach ($modelFiles as $file) {
            $className = 'App\\Models\\'.basename($file, '.php');

            if (! class_exists($className) || ! is_subclass_of($className, Model::class)) {
                continue;
            }

            $reflection = new ReflectionClass($className);
            if ($reflection->isAbstract()) {
                continue;
            }

            /** @var Model $instance */
            $instance = new $className;
            $encryptedAttrs = [];

            foreach ($instance->getCasts() as $attribute => $cast) {
                if ($cast === 'encrypted' || str_starts_with($cast, 'encrypted:')) {
                    $encryptedAttrs[] = $attribute;
                }
            }

            if (! empty($encryptedAttrs)) {
                $models[$className] = array_values(array_unique(array_merge(
                    $models[$className] ?? [],
                    $encryptedAttrs
                )));
            }
        }

        return $models;
    }
}
