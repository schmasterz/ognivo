<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Audit\AuditConfigValidator;
use App\DTO\Cast;
use App\Mail\AuditVerificationFailedMail;
use App\Models\User;
use App\Services\AuditSealService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

final class AuditVerifyCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'audit:verify {--last= : Number of recent seals to verify}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cryptographically verify audit seals, row hashes, and HMAC signatures';

    /**
     * Execute the console command.
     */
    public function handle(AuditSealService $sealService): int
    {
        AuditConfigValidator::validateProductionAuditCommand('audit:verify');

        $lastOption = $this->option('last');
        $limit = ($lastOption !== null && $lastOption !== '') ? Cast::int($lastOption) : null;

        $result = $sealService->verifySeals($limit);

        if (! $result['ok']) {
            $failedId = (int) $result['first_failed_id'];

            // Log at critical level
            Log::critical("Audit seal verification failed: seal #{$failedId} failed verification (checked: {$result['checked']}).");

            // Notify active admins by mail
            try {
                $adminEmails = User::role('admin')->where('status', 'active')->pluck('email')->filter()->all();
                foreach ($adminEmails as $email) {
                    Mail::to($email)->send(new AuditVerificationFailedMail($failedId));
                }
            } catch (\Throwable $e) {
                Log::error('Failed to notify admins of audit verification failure: '.$e->getMessage());
            }

            $this->error("Audit seal verification failed at seal #{$failedId}. first_failed_id: {$failedId}");

            return self::FAILURE;
        }

        $this->info("Audit seals verified successfully ({$result['checked']} seals checked).");

        return self::SUCCESS;
    }
}
