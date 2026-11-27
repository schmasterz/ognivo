<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Enums\QueueName;
use App\Models\PanelCertificate;
use App\States\PanelCertificate\Active;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class WarnExpiringPanelCertificateJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct()
    {
        $this->onQueue(QueueName::Default);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        /** @var PanelCertificate|null $activeCert */
        $activeCert = PanelCertificate::where('status', Active::$name)->first();
        if ($activeCert === null) {
            Log::warning('[Incus Certificate Alert] No active panel mTLS certificate configured.');

            return;
        }

        if ($activeCert->isExpired()) {
            Log::error(sprintf(
                '[Incus Certificate Alert] Active panel mTLS certificate (Fingerprint: %s) expired on %s!',
                $activeCert->fingerprint,
                $activeCert->not_after->toIso8601String()
            ));

            return;
        }

        if ($activeCert->isExpiringSoon(30)) {
            $daysLeft = now()->diffInDays($activeCert->not_after);
            Log::warning(sprintf(
                '[Incus Certificate Warning] Active panel mTLS certificate (Fingerprint: %s) expires in %d days (%s). Please rotate your certificate.',
                $activeCert->fingerprint,
                $daysLeft,
                $activeCert->not_after->toIso8601String()
            ));
        }
    }
}
