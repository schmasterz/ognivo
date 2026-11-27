<?php

declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditAction;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Audit\AuditOutcome;
use App\Models\Host;
use App\Models\PanelCertificate;
use App\States\PanelCertificate\Active;
use App\States\PanelCertificate\Retiring;
use App\States\PanelCertificate\Revoked;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use OpenSSLCertificateSigningRequest;
use RuntimeException;

/**
 * Service managing the control panel's self-generated EC mTLS client certificate.
 */
final class PanelCertificateService
{
    public const int CACHE_TTL_SECONDS = 60;

    private ?PanelCertificate $cachedActive = null;

    private ?PanelCertificate $cachedRetiring = null;

    private ?string $cachedActivePrivateKey = null;

    private float $activeCacheTimestamp = 0.0;

    private float $retiringCacheTimestamp = 0.0;

    public function __construct(
        private ?AuditLogger $auditLogger = null,
    ) {
        $this->auditLogger ??= app(AuditLogger::class);
    }

    private function logger(): AuditLogger
    {
        return $this->auditLogger ??= app(AuditLogger::class);
    }

    /**
     * Generate a new EC client certificate (curve secp384r1, SHA-384, CN incus-panel, clientAuth).
     * If an active certificate exists, marks it as retiring and flags all hosts as needs_retrust.
     *
     * @throws RuntimeException If OpenSSL fails to generate key or certificate
     */
    public function generate(int $validityDays = 365): PanelCertificate
    {
        if ($validityDays <= 0) {
            throw new RuntimeException('Validity days must be greater than zero.');
        }

        $lock = Cache::lock('panel_certificate_generation', 15);

        return $lock->block(10, function () use ($validityDays): PanelCertificate {
            /** @var OpenSSLAsymmetricKey|false $key */
            $key = openssl_pkey_new([
                'private_key_type' => OPENSSL_KEYTYPE_EC,
                'curve_name' => 'secp384r1',
            ]);

            if (! $key instanceof OpenSSLAsymmetricKey) {
                throw new RuntimeException('Failed to generate EC private key (secp384r1).');
            }

            $tmpCnf = tempnam(sys_get_temp_dir(), 'incus_cnf_');
            if ($tmpCnf === false) {
                throw new RuntimeException('Failed to create temporary OpenSSL configuration file.');
            }

            $cnfContent = "[ req ]\n".
                "distinguished_name = req_distinguished_name\n".
                "[ req_distinguished_name ]\n".
                "[ v3_req ]\n".
                "basicConstraints = CA:FALSE\n".
                "keyUsage = nonRepudiation, digitalSignature, keyEncipherment\n".
                "extendedKeyUsage = clientAuth\n";

            file_put_contents($tmpCnf, $cnfContent);

            try {
                $dn = ['commonName' => 'incus-panel'];

                /** @var OpenSSLCertificateSigningRequest|false $csr */
                $csr = openssl_csr_new($dn, $key, [
                    'digest_alg' => 'sha384',
                    'config' => $tmpCnf,
                    'req_extensions' => 'v3_req',
                ]);

                if (! $csr instanceof OpenSSLCertificateSigningRequest) {
                    throw new RuntimeException('Failed to create certificate signing request.');
                }

                /** @var OpenSSLAsymmetricKey $key */
                $cert = openssl_csr_sign($csr, null, $key, $validityDays, [
                    'digest_alg' => 'sha384',
                    'config' => $tmpCnf,
                    'x509_extensions' => 'v3_req',
                ]);

                if (! $cert instanceof OpenSSLCertificate) {
                    throw new RuntimeException('Failed to sign client certificate.');
                }

                $certPem = '';
                $keyPem = '';
                openssl_x509_export($cert, $certPem);
                openssl_pkey_export($key, $keyPem);

                /** @var string $certPem */
                if ($certPem === '') {
                    throw new RuntimeException('Failed to export certificate PEM.');
                }

                /** @var array<string, mixed>|false $parsed */
                $parsed = openssl_x509_parse($certPem);
                if ($parsed === false) {
                    throw new RuntimeException('Failed to parse newly generated certificate.');
                }

                $validFrom = isset($parsed['validFrom_time_t']) && is_numeric($parsed['validFrom_time_t'])
                    ? Carbon::createFromTimestamp((int) $parsed['validFrom_time_t'])
                    : now();

                $validTo = isset($parsed['validTo_time_t']) && is_numeric($parsed['validTo_time_t'])
                    ? Carbon::createFromTimestamp((int) $parsed['validTo_time_t'])
                    : now()->addDays($validityDays);

                $fingerprint = strtolower((string) openssl_x509_fingerprint($certPem, 'sha256'));

                /** @var PanelCertificate $newCert */
                $newCert = DB::transaction(function () use ($certPem, $keyPem, $fingerprint, $validFrom, $validTo): PanelCertificate {
                    // If active certificate exists, mark it as retiring and clear active_marker
                    $existingActive = PanelCertificate::where('status', Active::$name)
                        ->lockForUpdate()
                        ->first();

                    $isRotating = $existingActive !== null;
                    if ($existingActive !== null) {
                        $existingActive->status->transitionTo(Retiring::class);
                        // Flag all existing hosts as needing re-trust with the new certificate
                        Host::query()->update(['needs_retrust' => true]);
                    }

                    /** @var PanelCertificate $certModel */
                    $certModel = PanelCertificate::create([
                        'certificate' => $certPem,
                        'private_key' => $keyPem,
                        'fingerprint' => $fingerprint,
                        'not_before' => $validFrom,
                        'not_after' => $validTo,
                        'status' => Active::class,
                        'active_marker' => 1,
                    ]);

                    if ($isRotating) {
                        $this->logger()->record(new AuditEntry(
                            action: AuditAction::CertRotated,
                            outcome: AuditOutcome::Success,
                            subject: $certModel,
                            tenantUserId: null,
                            visibility: 'admin',
                            context: [
                                'previous_fingerprint' => $existingActive->fingerprint,
                                'new_fingerprint' => $certModel->fingerprint,
                            ],
                        ));
                    } else {
                        $this->logger()->record(new AuditEntry(
                            action: AuditAction::CertGenerated,
                            outcome: AuditOutcome::Success,
                            subject: $certModel,
                            tenantUserId: null,
                            visibility: 'admin',
                            context: [
                                'fingerprint' => $certModel->fingerprint,
                            ],
                        ));
                    }

                    return $certModel;
                });

                $this->clearMemoryCache();

                return $newCert;
            } finally {
                if (file_exists($tmpCnf)) {
                    @unlink($tmpCnf);
                }
            }
        });
    }

    /**
     * Get the currently active panel certificate.
     */
    public function getActiveCertificate(): ?PanelCertificate
    {
        if ($this->cachedActive !== null && (microtime(true) - $this->activeCacheTimestamp) < self::CACHE_TTL_SECONDS) {
            return $this->cachedActive;
        }

        /** @var PanelCertificate|null $cert */
        $cert = PanelCertificate::where('active_marker', 1)->first()
            ?? PanelCertificate::where('status', Active::$name)->first();

        $this->cachedActive = $cert;
        $this->cachedActivePrivateKey = null;
        $this->activeCacheTimestamp = microtime(true);

        return $cert;
    }

    /**
     * Get the retiring panel certificate if one is in rotation.
     */
    public function getRetiringCertificate(): ?PanelCertificate
    {
        if ($this->cachedRetiring !== null && (microtime(true) - $this->retiringCacheTimestamp) < self::CACHE_TTL_SECONDS) {
            return $this->cachedRetiring;
        }

        /** @var PanelCertificate|null $cert */
        $cert = PanelCertificate::where('status', Retiring::$name)->first();

        $this->cachedRetiring = $cert;
        $this->retiringCacheTimestamp = microtime(true);

        return $cert;
    }

    /**
     * Get the decrypted private key of the active certificate, memoized in memory.
     */
    public function getActivePrivateKey(): ?string
    {
        $active = $this->getActiveCertificate();
        if ($active === null) {
            return null;
        }

        return $this->cachedActivePrivateKey ??= $active->private_key;
    }

    /**
     * Clear in-memory cached certificate instances and keys.
     */
    public function clearMemoryCache(): void
    {
        $this->cachedActive = null;
        $this->cachedRetiring = null;
        $this->cachedActivePrivateKey = null;
        $this->activeCacheTimestamp = 0.0;
        $this->retiringCacheTimestamp = 0.0;
    }

    /**
     * Get the public certificate PEM of the active certificate.
     */
    public function getPublicCertificatePem(): string
    {
        $active = $this->getActiveCertificate();
        if ($active === null) {
            throw new RuntimeException('No active panel certificate exists.');
        }

        if ($active->isExpired()) {
            throw new RuntimeException('The active panel certificate has expired.');
        }

        return $active->certificate;
    }

    /**
     * Revoke the retiring certificate once all hosts are confirmed re-trusted.
     * Returns the command instruction for the administrator.
     */
    public function revokeOldCertificate(): string
    {
        $retiring = $this->getRetiringCertificate();
        if ($retiring === null) {
            throw new RuntimeException('No retiring certificate found to revoke.');
        }

        $pendingHostsCount = Host::where('needs_retrust', true)->count();
        if ($pendingHostsCount > 0) {
            throw new RuntimeException(
                sprintf('Cannot revoke old certificate: %d host(s) still need to be re-trusted with the new certificate.', $pendingHostsCount)
            );
        }

        $fingerprint = $retiring->fingerprint;
        $retiring->status->transitionTo(Revoked::class);
        $this->clearMemoryCache();

        $this->logger()->record(new AuditEntry(
            action: AuditAction::CertRevoked,
            outcome: AuditOutcome::Success,
            subject: $retiring,
            tenantUserId: null,
            visibility: 'admin',
            context: [
                'fingerprint' => $fingerprint,
            ],
        ));

        return sprintf('incus config trust remove %s', $fingerprint);
    }

    /**
     * Download and record the active panel certificate download.
     */
    public function downloadCertificate(): PanelCertificate
    {
        $active = $this->getActiveCertificate();
        if ($active === null) {
            $active = $this->generate();
            $this->clearMemoryCache();
        }

        $this->logger()->record(new AuditEntry(
            action: AuditAction::CertDownloaded,
            outcome: AuditOutcome::Success,
            subject: $active,
            tenantUserId: null,
            visibility: 'admin',
            context: [
                'fingerprint' => $active->fingerprint,
            ],
        ));

        return $active;
    }

    /**
     * Mark a host as having confirmed trust for the current active certificate.
     */
    public function confirmHostRetrusted(Host $host): void
    {
        $host->update(['needs_retrust' => false]);
    }
}
