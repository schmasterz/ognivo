<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Audit\AuditAction;
use App\Audit\AuditConfigValidator;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Audit\AuditOutcome;
use App\DTO\Cast;
use App\Models\AuditLog;
use App\Services\AuditSealService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class AuditPseudonymiseCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'audit:pseudonymise {userId : The ID of the deleted user to pseudonymise}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scrub personal data and pseudonymise audit records for a deleted user';

    /**
     * Execute the console command.
     */
    public function handle(AuditLogger $logger): int
    {
        AuditConfigValidator::validateProductionAuditCommand('audit:pseudonymise');

        $userId = Cast::int($this->argument('userId'));
        $salt = Cast::string(config('audit.pseudonym_salt', ''));
        $hash = substr(hash('sha256', $userId.':'.$salt), 0, 8);
        $pseudonym = "user-{$hash}";

        $maintConn = AuditSealService::maintenanceConnection();

        /** @var Collection<int, AuditLog> $rows */
        $rows = AuditLog::on($maintConn)
            ->where(function (Builder $q) use ($userId): void {
                $q->where('actor_id', $userId)
                    ->orWhere('tenant_user_id', $userId)
                    ->orWhere(function (Builder $sq) use ($userId): void {
                        $sq->whereIn('subject_type', ['user', 'App\\Models\\User'])
                            ->where('subject_id', (string) $userId);
                    });
            })
            ->get();

        $updatedCount = 0;
        foreach ($rows as $row) {
            if ($row->actor_label !== null) {
                $row->actor_label = $pseudonym;
            }
            if ($row->subject_label !== null) {
                $row->subject_label = $pseudonym;
            }

            $row->ip = null;
            $row->user_agent = null;

            $context = $row->context ?? [];
            $context['pii'] = ['pseudonymised' => true];
            $row->context = $context;

            $row->save();
            $updatedCount++;
        }

        // Records its own audit entry
        $logger->recordIndependent(new AuditEntry(
            action: AuditAction::UserPseudonymised,
            outcome: AuditOutcome::Success,
            subject: null,
            tenantUserId: null,
            visibility: 'admin',
            context: [
                'target_user_id' => $userId,
                'pseudonym' => $pseudonym,
                'rows_affected' => $updatedCount,
            ],
        ));

        $this->info("Pseudonymised {$updatedCount} audit records for user #{$userId} with label [{$pseudonym}].");

        return self::SUCCESS;
    }
}
