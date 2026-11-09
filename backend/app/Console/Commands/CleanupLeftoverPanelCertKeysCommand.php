<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class CleanupLeftoverPanelCertKeysCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hypervisor:cleanup-cert-keys';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove leftover panel_cert_*.key files from storage and temporary directories';

    /**
     * The console command aliases.
     *
     * @var list<string>
     */
    protected $aliases = [
        'certs:cleanup-leftover-keys',
        'incus:cleanup-cert-keys',
    ];

    public function handle(): int
    {
        $directories = [
            storage_path('app/lxd-certs'),
            storage_path('app'),
            sys_get_temp_dir(),
        ];

        $deletedCount = 0;

        foreach ($directories as $dir) {
            if (! is_dir($dir)) {
                continue;
            }

            // Look for panel_cert_*.key files
            $files = glob(rtrim($dir, '/').'/panel_cert_*.key') ?: [];
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                    $this->line(sprintf('Deleted leftover key file: %s', $file));
                    $deletedCount++;
                }
            }

            // Also remove any panel_cert_*.crt files from lxd-certs
            $certs = glob(rtrim($dir, '/').'/panel_cert_*.crt') ?: [];
            foreach ($certs as $certFile) {
                if (is_file($certFile)) {
                    @unlink($certFile);
                    $deletedCount++;
                }
            }

            // Also check for any leftover incus_client_key_*, incus_key_* in temp
            if ($dir === sys_get_temp_dir()) {
                $tempKeys = glob(rtrim($dir, '/').'/incus_*key_*') ?: [];
                foreach ($tempKeys as $tempKey) {
                    if (is_file($tempKey)) {
                        @unlink($tempKey);
                        $deletedCount++;
                    }
                }
            }
        }

        // Clean up empty lxd-certs directory if present
        $lxdCertsDir = storage_path('app/lxd-certs');
        if (is_dir($lxdCertsDir)) {
            $remaining = array_diff(scandir($lxdCertsDir) ?: [], ['.', '..']);
            if (empty($remaining)) {
                @rmdir($lxdCertsDir);
                $this->line('Removed empty directory: '.$lxdCertsDir);
            }
        }

        $this->info(sprintf('Cleanup completed. Removed %d leftover file(s).', $deletedCount));

        return self::SUCCESS;
    }
}
