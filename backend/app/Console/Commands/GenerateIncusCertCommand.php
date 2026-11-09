<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PanelCertificateService;
use Illuminate\Console\Command;

final class GenerateIncusCertCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'incus:generate-cert
                            {--days=365 : Validity period in days (default: 365)}
                            {--force : Force rotation if an active certificate already exists}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate or rotate the self-signed EC client mTLS certificate for Incus communication';

    public function handle(PanelCertificateService $certificateService): int
    {
        $daysOption = $this->option('days');
        $days = is_numeric($daysOption) ? (int) $daysOption : 365;
        if ($days <= 0) {
            $days = 365;
        }

        $active = $certificateService->getActiveCertificate();
        if ($active !== null && ! (bool) $this->option('force')) {
            $this->warn(sprintf(
                'An active panel certificate already exists (Fingerprint: %s, expires: %s).',
                $active->fingerprint,
                $active->not_after->toDateTimeString()
            ));
            $this->info('Use --force to rotate to a new certificate.');

            return self::FAILURE;
        }

        $cert = $certificateService->generate($days);

        $this->info('Panel client mTLS certificate successfully generated!');
        $this->table(
            ['ID', 'Fingerprint', 'Status', 'Valid From', 'Valid To'],
            [[
                (string) $cert->id,
                $cert->fingerprint,
                $cert->status,
                $cert->not_before->toDateTimeString(),
                $cert->not_after->toDateTimeString(),
            ]]
        );

        if ($active !== null) {
            $this->warn('Previous certificate was marked as "retiring". All hosts have been flagged as "needs re-trust".');
            $this->info(sprintf('After re-trusting all hosts, revoke the old certificate with fingerprint: %s', $active->fingerprint));
        }

        return self::SUCCESS;
    }
}
