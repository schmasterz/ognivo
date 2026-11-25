<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Host;
use App\Models\PanelCertificate;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\SystemOverviewService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

final class ReencryptSecretsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Model::encryptUsing(null);
        parent::tearDown();
    }

    /**
     * Create encrypted Host, PanelCertificate, and SystemSetting fixtures using the given encrypter.
     *
     * @return array{
     *     host: Host,
     *     cert: PanelCertificate,
     *     expectedToken: string,
     *     expectedPrivateKey: string,
     *     expectedSmtpPassword: string,
     *     initialRawToken: string,
     *     initialRawCertKey: string,
     *     initialRawSmtpValue: string,
     * }
     */
    private function createEncryptedFixtures(Encrypter $encrypter): array
    {
        Crypt::swap($encrypter);
        Model::encryptUsing($encrypter);

        $expectedToken = 'host-auth-token-xyz-12345';
        $host = Host::create([
            'name' => 'compute-alpha',
            'endpoint_url' => 'https://10.0.0.10:8443',
            'driver' => 'incus',
            'token' => $expectedToken,
            'is_active' => true,
        ]);

        $expectedPrivateKey = "-----BEGIN EC PRIVATE KEY-----\nMHcCAQEEI...TEST...KEY\n-----END EC PRIVATE KEY-----";
        $cert = PanelCertificate::create([
            'certificate' => 'dummy-cert-pem',
            'private_key' => $expectedPrivateKey,
            'fingerprint' => 'test-fingerprint-sha256',
            'not_before' => now(),
            'not_after' => now()->addYear(),
            'status' => PanelCertificate::STATUS_ACTIVE,
        ]);

        $expectedSmtpPassword = 'smtp-secret-pass-999';
        app(SystemOverviewService::class)->setSmtpSettings([
            'enabled' => true,
            'host' => 'smtp.example.com',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'mailer',
            'password' => $expectedSmtpPassword,
        ]);

        $initialRawToken = DB::table('hosts')->where('id', $host->id)->value('token');
        $initialRawCertKey = DB::table('panel_certificates')->where('id', $cert->id)->value('private_key');
        $initialRawSmtpValue = DB::table('system_settings')->where('key', 'smtp_settings')->value('value');

        $this->assertIsString($initialRawToken);
        $this->assertIsString($initialRawCertKey);
        $this->assertIsString($initialRawSmtpValue);

        return [
            'host' => $host,
            'cert' => $cert,
            'expectedToken' => $expectedToken,
            'expectedPrivateKey' => $expectedPrivateKey,
            'expectedSmtpPassword' => $expectedSmtpPassword,
            'initialRawToken' => $initialRawToken,
            'initialRawCertKey' => $initialRawCertKey,
            'initialRawSmtpValue' => $initialRawSmtpValue,
        ];
    }

    public function test_reencrypt_secrets_keeps_decrypted_values_identical_after_key_rotation(): void
    {
        // 1. Generate two distinct 32-byte encryption keys
        $oldKey = random_bytes(32);
        $newKey = random_bytes(32);

        $oldEncrypter = new Encrypter($oldKey, 'AES-256-CBC');
        $newEncrypterWithPrevious = new Encrypter($newKey, 'AES-256-CBC');
        $newEncrypterWithPrevious->previousKeys([$oldKey]);

        $newEncrypterOnly = new Encrypter($newKey, 'AES-256-CBC');

        // 2. Encrypt and save records using the old key
        $fixtures = $this->createEncryptedFixtures($oldEncrypter);
        $host = $fixtures['host'];
        $cert = $fixtures['cert'];
        $expectedToken = $fixtures['expectedToken'];
        $expectedPrivateKey = $fixtures['expectedPrivateKey'];
        $expectedSmtpPassword = $fixtures['expectedSmtpPassword'];
        $initialRawToken = $fixtures['initialRawToken'];
        $initialRawCertKey = $fixtures['initialRawCertKey'];
        $initialRawSmtpValue = $fixtures['initialRawSmtpValue'];

        $this->assertNotEquals($expectedToken, $initialRawToken);
        $this->assertNotEquals($expectedPrivateKey, $initialRawCertKey);
        $this->assertStringNotContainsString($expectedSmtpPassword, $initialRawSmtpValue);

        // 3. Switch active encrypter to the new key with APP_PREVIOUS_KEYS containing the old key
        Crypt::swap($newEncrypterWithPrevious);
        Model::encryptUsing($newEncrypterWithPrevious);

        // Verify that before re-encryption, the records can be decrypted thanks to previousKeys
        $this->assertSame($expectedToken, Host::findOrFail($host->id)->token);
        $this->assertSame($expectedPrivateKey, PanelCertificate::findOrFail($cert->id)->private_key);
        $this->assertSame($expectedSmtpPassword, SystemSetting::getSmtpSettings()['password']);

        // 4. Run the re-encryption command
        /** @var PendingCommand $command */
        $command = $this->artisan('app:reencrypt-secrets');
        $command->expectsOutputToContain('Re-encryption completed successfully')
            ->assertExitCode(0)
            ->run();

        // 5. Switch encrypter to the new key ALONE (no previous keys allowed)
        Crypt::swap($newEncrypterOnly);
        Model::encryptUsing($newEncrypterOnly);

        // 6. Verify that decrypted values are identical
        $reloadedHost = Host::findOrFail($host->id);
        $reloadedCert = PanelCertificate::findOrFail($cert->id);

        $this->assertSame($expectedToken, $reloadedHost->token);
        $this->assertSame($expectedPrivateKey, $reloadedCert->private_key);
        $this->assertSame($expectedSmtpPassword, SystemSetting::getSmtpSettings()['password']);

        // 7. Verify that database ciphertexts have changed
        $updatedRawToken = DB::table('hosts')->where('id', $host->id)->value('token');
        $updatedRawCertKey = DB::table('panel_certificates')->where('id', $cert->id)->value('private_key');
        $updatedRawSmtpValue = DB::table('system_settings')->where('key', 'smtp_settings')->value('value');

        $this->assertNotEquals($initialRawToken, $updatedRawToken);
        $this->assertNotEquals($initialRawCertKey, $updatedRawCertKey);
        $this->assertNotEquals($initialRawSmtpValue, $updatedRawSmtpValue);

        // 8. Verify that the old encrypter alone CANNOT decrypt the newly saved ciphertexts
        $this->expectException(DecryptException::class);
        $this->assertIsString($updatedRawToken);
        $oldEncrypter->decryptString($updatedRawToken);
    }

    public function test_reencrypt_secrets_dry_run_does_not_modify_database(): void
    {
        $oldKey = random_bytes(32);
        $newKey = random_bytes(32);

        $oldEncrypter = new Encrypter($oldKey, 'AES-256-CBC');
        $newEncrypterWithPrevious = new Encrypter($newKey, 'AES-256-CBC');
        $newEncrypterWithPrevious->previousKeys([$oldKey]);
        $newEncrypterOnly = new Encrypter($newKey, 'AES-256-CBC');

        $fixtures = $this->createEncryptedFixtures($oldEncrypter);
        $host = $fixtures['host'];
        $cert = $fixtures['cert'];

        Crypt::swap($newEncrypterWithPrevious);
        Model::encryptUsing($newEncrypterWithPrevious);

        /** @var PendingCommand $dryRunCommand */
        $dryRunCommand = $this->artisan('app:reencrypt-secrets', ['--dry-run' => true]);
        $dryRunCommand->expectsOutputToContain('No database changes were persisted')
            ->expectsOutputToContain('[DRY-RUN]')
            ->assertExitCode(0)
            ->run();

        // Raw database ciphertexts must remain completely unchanged
        $this->assertSame($fixtures['initialRawToken'], DB::table('hosts')->where('id', $host->id)->value('token'));
        $this->assertSame($fixtures['initialRawCertKey'], DB::table('panel_certificates')->where('id', $cert->id)->value('private_key'));
        $this->assertSame($fixtures['initialRawSmtpValue'], DB::table('system_settings')->where('key', 'smtp_settings')->value('value'));

        // Old encrypter must still be able to decrypt the untouched DB ciphertext
        $this->assertSame($fixtures['expectedToken'], $oldEncrypter->decryptString($fixtures['initialRawToken']));

        // Dry-run must fail with exit code 1 if decryption fails (e.g. missing APP_PREVIOUS_KEYS)
        Crypt::swap($newEncrypterOnly);
        Model::encryptUsing($newEncrypterOnly);

        /** @var PendingCommand $failingDryRun */
        $failingDryRun = $this->artisan('app:reencrypt-secrets', ['--dry-run' => true]);
        $failingDryRun->expectsOutputToContain('[DRY-RUN]')
            ->assertExitCode(1)
            ->run();
    }

    public function test_reencrypt_secrets_status_exits_one_on_previous_key_and_zero_when_migrated(): void
    {
        $oldKey = random_bytes(32);
        $newKey = random_bytes(32);

        $oldEncrypter = new Encrypter($oldKey, 'AES-256-CBC');
        $newEncrypterWithPrevious = new Encrypter($newKey, 'AES-256-CBC');
        $newEncrypterWithPrevious->previousKeys([$oldKey]);

        $this->createEncryptedFixtures($oldEncrypter);

        // Rotate key while old key is still in APP_PREVIOUS_KEYS
        Crypt::swap($newEncrypterWithPrevious);
        Model::encryptUsing($newEncrypterWithPrevious);

        // --status must exit 1 while secrets still rely on APP_PREVIOUS_KEYS
        /** @var PendingCommand $statusBefore */
        $statusBefore = $this->artisan('app:reencrypt-secrets', ['--status' => true]);
        $statusBefore->expectsOutputToContain('Previous Key')
            ->expectsOutputToContain('APP_PREVIOUS_KEYS')
            ->assertExitCode(1)
            ->run();

        // Re-encrypt all secrets onto the current key
        /** @var PendingCommand $reencryptCommand */
        $reencryptCommand = $this->artisan('app:reencrypt-secrets');
        $reencryptCommand->assertExitCode(0)->run();

        // --status must now exit 0 and report 0 secrets on a previous key
        /** @var PendingCommand $statusAfter */
        $statusAfter = $this->artisan('app:reencrypt-secrets', ['--status' => true]);
        $statusAfter->expectsOutputToContain('All secrets are encrypted with the current APP_KEY. 0 secrets on a previous key.')
            ->assertExitCode(0)
            ->run();
    }

    public function test_reencrypt_secrets_status_exits_one_when_undecryptable_secrets_exist(): void
    {
        $oldKey = random_bytes(32);
        $newKey = random_bytes(32);

        $oldEncrypter = new Encrypter($oldKey, 'AES-256-CBC');
        $newEncrypterOnly = new Encrypter($newKey, 'AES-256-CBC');

        $this->createEncryptedFixtures($oldEncrypter);

        Crypt::swap($newEncrypterOnly);
        Model::encryptUsing($newEncrypterOnly);

        /** @var PendingCommand $statusCommand */
        $statusCommand = $this->artisan('app:reencrypt-secrets', ['--status' => true]);
        $statusCommand->expectsOutputToContain('Undecryptable')
            ->assertExitCode(1)
            ->run();
    }

    public function test_reencrypt_secrets_include_users_scans_user_records(): void
    {
        User::factory()->create([
            'email' => 'reencrypt-check@example.com',
        ]);

        /** @var PendingCommand $normalCommand */
        $normalCommand = $this->artisan('app:reencrypt-secrets', ['--include-users' => true]);
        $normalCommand->expectsOutputToContain('Processing [App\Models\User] (--include-users enabled)...')
            ->expectsOutputToContain('Checked 1 user record(s)')
            ->assertExitCode(0)
            ->run();

        /** @var PendingCommand $dryRunCommand */
        $dryRunCommand = $this->artisan('app:reencrypt-secrets', [
            '--dry-run' => true,
            '--include-users' => true,
        ]);
        $dryRunCommand->expectsOutputToContain('[DRY-RUN] Processing [App\Models\User] (--include-users enabled)...')
            ->expectsOutputToContain('[DRY-RUN]  -> Checked 1 user record(s)')
            ->assertExitCode(0)
            ->run();

        /** @var PendingCommand $statusCommand */
        $statusCommand = $this->artisan('app:reencrypt-secrets', [
            '--status' => true,
            '--include-users' => true,
        ]);
        $statusCommand->expectsOutputToContain('Checked 1 user record(s)')
            ->expectsOutputToContain('App\Models\User')
            ->expectsOutputToContain('All secrets are encrypted with the current APP_KEY. 0 secrets on a previous key.')
            ->assertExitCode(0)
            ->run();
    }

    public function test_smtp_settings_falls_back_to_plaintext_password_if_decryption_fails(): void
    {
        DB::table('system_settings')->insert([
            'key' => 'smtp_settings',
            'value' => json_encode([
                'enabled' => true,
                'host' => 'smtp.legacy.com',
                'port' => 587,
                'encryption' => 'tls',
                'username' => 'legacy-user',
                'password' => 'legacy-plaintext-secret',
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $settings = SystemSetting::getSmtpSettings();
        $this->assertSame('legacy-plaintext-secret', $settings['password']);
    }
}
