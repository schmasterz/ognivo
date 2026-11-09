<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Audit\AuditConfigValidator;
use App\DTO\Cast;
use App\Http\Resources\AdminAuditEntryResource;
use App\Models\AuditLog;
use App\Models\AuditSeal;
use App\Services\AuditSealService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class AuditPruneCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'audit:prune';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune and archive expired audit logs and scrub unauthenticated login emails';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        AuditConfigValidator::validateProductionAuditCommand('audit:prune');

        $maintConn = AuditSealService::maintenanceConnection();

        // 1. Scrub context.pii.attempted_email from auth.login.failed rows older than AUDIT_ATTEMPTED_EMAIL_DAYS (30)
        $this->scrubAttemptedEmails($maintConn);

        // 2. Export and prune rows older than AUDIT_RETENTION_DAYS (400) and already sealed
        $this->pruneExpiredAuditLogs($maintConn);

        return self::SUCCESS;
    }

    /**
     * Remove context.pii.attempted_email from auth.login.failed rows older than AUDIT_ATTEMPTED_EMAIL_DAYS.
     */
    private function scrubAttemptedEmails(string $maintConn): void
    {
        $days = Cast::int(config('audit.attempted_email_days', 30));
        $cutoff = Carbon::now()->subDays($days);

        $rows = DB::connection($maintConn)
            ->table('audit_logs')
            ->where('action', 'auth.login.failed')
            ->where('occurred_at', '<', $cutoff)
            ->get(['id', 'context']);

        $scrubbedCount = 0;
        foreach ($rows as $row) {
            if ($row->context === null || $row->context === '') {
                continue;
            }

            /** @var array<string, mixed>|null $context */
            $context = is_string($row->context) ? json_decode($row->context, true) : (array) $row->context;
            if (is_array($context) && isset($context['pii']) && is_array($context['pii']) && isset($context['pii']['attempted_email'])) {
                unset($context['pii']['attempted_email']);
                if (empty($context['pii'])) {
                    unset($context['pii']);
                }

                $updated = empty($context) ? null : json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                DB::connection($maintConn)
                    ->table('audit_logs')
                    ->where('id', $row->id)
                    ->update(['context' => $updated]);

                $scrubbedCount++;
            }
        }

        if ($scrubbedCount > 0) {
            $this->info("Scrubbed attempted_email from {$scrubbedCount} failed login record(s) older than {$days} days.");
        }
    }

    /**
     * Export and prune sealed rows older than AUDIT_RETENTION_DAYS.
     */
    private function pruneExpiredAuditLogs(string $maintConn): void
    {
        $retentionDays = Cast::int(config('audit.retention_days', 400));
        $retentionCutoff = Carbon::now()->subDays($retentionDays);

        /** @var Collection<int, AuditSeal> $unarchivedSeals */
        $unarchivedSeals = AuditSeal::where('archived', false)->orderBy('id', 'asc')->get();

        if ($unarchivedSeals->isEmpty()) {
            $this->info('No unarchived audit seals found.');

            return;
        }

        // Identify covering seals whose entire row range is older than cutoff
        $eligibleSeals = [];
        foreach ($unarchivedSeals as $seal) {
            $toRowOccurredAt = DB::table('audit_logs')->where('id', $seal->to_id)->value('occurred_at');
            if ($toRowOccurredAt !== null && Carbon::parse(Cast::string($toRowOccurredAt))->lte($retentionCutoff)) {
                $eligibleSeals[] = $seal;
            }
        }

        if (empty($eligibleSeals)) {
            $this->info("No sealed audit logs older than retention period ({$retentionDays} days) eligible for pruning.");

            return;
        }

        // Group eligible seals by month (YYYY-MM)
        /** @var array<string, list<AuditSeal>> $sealsByMonth */
        $sealsByMonth = [];
        foreach ($eligibleSeals as $seal) {
            $fromRowOccurredAt = DB::table('audit_logs')->where('id', $seal->from_id)->value('occurred_at');
            $date = $fromRowOccurredAt !== null ? Carbon::parse(Cast::string($fromRowOccurredAt)) : $seal->sealed_at;
            $month = $date->format('Y-m');
            $sealsByMonth[$month][] = $seal;
        }

        $archiveDir = storage_path('app/audit-archive');
        if (! is_dir($archiveDir)) {
            mkdir($archiveDir, 0755, true);
        }

        foreach ($sealsByMonth as $month => $seals) {
            $sealIds = array_map(fn (AuditSeal $s): int => (int) $s->id, $seals);
            $minId = min(array_map(fn (AuditSeal $s): int => (int) $s->from_id, $seals));
            $maxId = max(array_map(fn (AuditSeal $s): int => (int) $s->to_id, $seals));

            $archivePath = $archiveDir.'/'.$month.'.ndjson.gz';

            // 1. Export range to storage/app/audit-archive/YYYY-MM.ndjson.gz
            $gz = gzopen($archivePath, 'wb9');
            if ($gz === false) {
                $this->error("Failed to open archive file for writing: {$archivePath}");

                continue;
            }

            /** @var Collection<int, AuditLog> $rows */
            $rows = AuditLog::where('id', '>=', $minId)
                ->where('id', '<=', $maxId)
                ->orderBy('id', 'asc')
                ->get();

            foreach ($rows as $log) {
                $resource = (new AdminAuditEntryResource($log))->resolve();
                $line = json_encode($resource, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
                gzwrite($gz, $line);
            }
            gzclose($gz);

            // 2. Compute sha256 of the exported archive
            $archiveSha256 = hash_file('sha256', $archivePath);

            // 3. Mark covering seals archived=1 with archive_sha256
            AuditSeal::on($maintConn)
                ->whereIn('id', $sealIds)
                ->update([
                    'archived' => true,
                    'archive_sha256' => $archiveSha256,
                ]);

            // 4. Delete rows in chunks of 5000 by id (audit_maintenance connection)
            do {
                $chunkIds = DB::connection($maintConn)
                    ->table('audit_logs')
                    ->where('id', '>=', $minId)
                    ->where('id', '<=', $maxId)
                    ->orderBy('id', 'asc')
                    ->limit(5000)
                    ->pluck('id');

                if ($chunkIds->isEmpty()) {
                    break;
                }

                DB::connection($maintConn)
                    ->table('audit_logs')
                    ->whereIn('id', $chunkIds)
                    ->delete();
            } while ($chunkIds->count() === 5000);

            $this->info("Archived and pruned {$rows->count()} rows for {$month} into {$archivePath} (sha256: {$archiveSha256}).");
        }
    }
}
