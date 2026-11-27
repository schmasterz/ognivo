<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Audit\AuditConfigValidator;
use App\Models\AuditLog;
use App\Models\AuditSeal;
use App\Services\AuditSealService;
use App\Support\Cast;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

final class AuditSealCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'audit:seal';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cryptographically seal eligible audit logs into verifiable chained HMAC blocks';

    /**
     * Execute the console command.
     */
    public function handle(AuditSealService $sealService): int
    {
        AuditConfigValidator::validateProductionAuditCommand('audit:seal');

        $lastSeal = AuditSeal::orderBy('to_id', 'desc')->first();
        $lastToId = $lastSeal ? (int) $lastSeal->to_id : 0;
        $previousHash = $lastSeal ? $lastSeal->hash : null;

        $delaySeconds = Cast::int(config('audit.seal_delay_seconds', 120));
        $cutoff = Carbon::now()->subSeconds($delaySeconds);

        // Fetch candidate rows with id > last sealed to_id in id order
        $candidates = AuditLog::where('id', '>', $lastToId)
            ->orderBy('id', 'asc')
            ->limit(5000)
            ->get();

        $eligibleRows = [];
        foreach ($candidates as $row) {
            // WHILE occurred_at <= now() - AUDIT_SEAL_DELAY_SECONDS; stop at the first row that is newer (never skip over a row)
            if ($row->occurred_at > $cutoff) {
                break;
            }
            $eligibleRows[] = $row;
        }

        if (empty($eligibleRows)) {
            $this->info('No eligible audit logs to seal.');

            return self::SUCCESS;
        }

        $rowHashes = [];
        foreach ($eligibleRows as $row) {
            $rowHashes[] = $sealService->computeSingleRowHash($row);
        }

        $hash = hash('sha256', ($previousHash ?? '').'|'.implode('|', $rowHashes));
        $keyId = Cast::string(config('audit.hmac.current_key_id', 'v1'));
        $hmac = $sealService->computeHmac($hash, $keyId);

        $fromId = (int) $eligibleRows[0]->id;
        $toId = (int) end($eligibleRows)->id;

        // Insert into audit_seals through the 'audit' connection
        $auditConnection = AuditSealService::auditConnection();
        AuditSeal::on($auditConnection)->create([
            'from_id' => $fromId,
            'to_id' => $toId,
            'hash' => $hash,
            'previous_hash' => $previousHash,
            'hmac' => $hmac,
            'key_id' => $keyId,
            'sealed_at' => Carbon::now()->utc(),
            'archived' => false,
        ]);

        $this->info("Sealed block [{$fromId}..{$toId}] with key {$keyId} (hash: ".substr($hash, 0, 12).'...).');

        return self::SUCCESS;
    }
}
