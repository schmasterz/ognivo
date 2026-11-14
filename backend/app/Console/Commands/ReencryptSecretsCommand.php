<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Host;
use App\Models\PanelCertificate;
use App\Models\SystemSetting;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use ReflectionClass;

final class ReencryptSecretsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:reencrypt-secrets';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Re-encrypt all encrypted database attributes with the current APP_KEY';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Scanning models for encrypted attributes...');

        $modelsToProcess = $this->discoverEncryptedModels();

        if (empty($modelsToProcess)) {
            $this->warn('No models with encrypted attributes found.');

            return self::SUCCESS;
        }

        $totalRecords = 0;
        $totalErrors = 0;

        foreach ($modelsToProcess as $class => $attributes) {
            $attrList = implode(', ', $attributes);
            $this->line("Processing [{$class}] (attributes: {$attrList})...");

            $records = $class::all();
            $classCount = 0;

            foreach ($records as $record) {
                $updates = [];
                $recordError = false;

                foreach ($attributes as $attribute) {
                    try {
                        $plaintext = $record->getAttribute($attribute);
                    } catch (DecryptException $e) {
                        $this->error("Failed to decrypt attribute '{$attribute}' on {$class} #{$record->getKey()}: {$e->getMessage()}");
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
                    // Force update the table directly to ensure newly generated ciphertext is saved
                    $record->newQuery()->whereKey($record->getKey())->update($updates);
                    $classCount++;
                    $totalRecords++;
                }
            }

            $this->info(" -> Re-encrypted {$classCount} record(s) for [{$class}].");
        }

        $smtpSetting = SystemSetting::where('key', 'smtp_settings')->first();
        if ($smtpSetting !== null && is_array($smtpSetting->value)) {
            if (isset($smtpSetting->value['password']) && is_string($smtpSetting->value['password']) && $smtpSetting->value['password'] !== '') {
                try {
                    $plainPassword = Crypt::decryptString($smtpSetting->value['password']);
                    $value = $smtpSetting->value;
                    $value['password'] = Crypt::encryptString($plainPassword);
                    $smtpSetting->newQuery()->whereKey($smtpSetting->getKey())->update([
                        'value' => json_encode($value, JSON_THROW_ON_ERROR),
                    ]);
                    $totalRecords++;
                } catch (DecryptException $e) {
                    $this->error("Failed to decrypt SMTP password in SystemSetting: {$e->getMessage()}");
                    $totalErrors++;
                }
            }
        }

        if ($totalErrors > 0) {
            $this->error("Re-encryption finished with {$totalErrors} decryption error(s). Please check your APP_PREVIOUS_KEYS setting.");

            return self::FAILURE;
        }

        $this->info("Re-encryption completed successfully. Total records updated: {$totalRecords}.");

        return self::SUCCESS;
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
