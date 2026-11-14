<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Host;
use App\Models\PanelCertificate;
use App\Models\SystemSetting;
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
        Crypt::swap($oldEncrypter);
        Model::encryptUsing($oldEncrypter);

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

        // Capture initial ciphertexts from the database
        $initialRawToken = DB::table('hosts')->where('id', $host->id)->value('token');
        $initialRawCertKey = DB::table('panel_certificates')->where('id', $cert->id)->value('private_key');
        $initialRawSmtpValue = DB::table('system_settings')->where('key', 'smtp_settings')->value('value');

        $this->assertNotEquals($expectedToken, $initialRawToken);
        $this->assertNotEquals($expectedPrivateKey, $initialRawCertKey);
        $this->assertIsString($initialRawSmtpValue);
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
