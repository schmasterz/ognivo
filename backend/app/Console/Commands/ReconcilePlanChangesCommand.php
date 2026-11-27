<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Audit\AuditAction;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Audit\AuditOutcome;
use App\DTO\Cast;
use App\Events\Vps\VpsPlanChangeCancelled;
use App\Jobs\ApplyVpsPlanChangeJob;
use App\Jobs\ReconcileVpsPlanChangeJob;
use App\Models\VpsPlanChange;
use App\States\VpsPlanChange\Applying;
use App\States\VpsPlanChange\AwaitingAuthorization;
use App\States\VpsPlanChange\Cancelled;
use App\States\VpsPlanChange\Pending;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scheduled command that sweeps stale VPS plan-change operations and recovers or expires them.
 */
final class ReconcilePlanChangesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vps:reconcile-plan-changes';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sweep stale VPS plan-change operations (pending, applying, and expired authorizations)';

    /**
     * Execute the console command.
     */
    public function handle(AuditLogger $auditLogger): int
    {
        $now = Carbon::now();

        $pendingMinutes = max(1, Cast::int(config('vps.pending_stale_minutes', 15), 15));
        $applyingMinutes = max(1, Cast::int(config('vps.applying_stale_minutes', 30), 30));
        $authTimeoutMinutes = max(1, Cast::int(config('vps.authorization_timeout_minutes', 60), 60));

        $pendingCutoff = $now->copy()->subMinutes($pendingMinutes);
        $applyingCutoff = $now->copy()->subMinutes($applyingMinutes);
        $authCutoff = $now->copy()->subMinutes($authTimeoutMinutes);

        $redispatched = $this->sweepStalePending($pendingCutoff, $auditLogger);
        $reconciled = $this->sweepStaleApplying($applyingCutoff, $now);
        $timedOut = $this->sweepExpiredAuthorizations($authCutoff, $auditLogger);

        $this->info(sprintf(
            'Plan-change reconciliation sweep finished. Pending re-dispatched: %d, Applying reconciled: %d, Authorizations timed out: %d.',
            $redispatched,
            $reconciled,
            $timedOut,
        ));

        return self::SUCCESS;
    }

    private function sweepStalePending(Carbon $cutoff, AuditLogger $auditLogger): int
    {
        $stalePending = VpsPlanChange::query()
            ->where('status', Pending::$name)
            ->where(function (Builder $query) use ($cutoff): void {
                $query->where('updated_at', '<=', $cutoff)
                    ->orWhere(function (Builder $sub) use ($cutoff): void {
                        $sub->whereColumn('updated_at', 'created_at')
                            ->where('requested_at', '<=', $cutoff);
                    });
            })
            ->get();

        $count = 0;

        foreach ($stalePending as $change) {
            try {
                $dispatched = DB::transaction(function () use ($change, $auditLogger): bool {
                    /** @var VpsPlanChange|null $locked */
                    $locked = VpsPlanChange::query()
                        ->where('id', $change->id)
                        ->lockForUpdate()
                        ->first();

                    if ($locked === null || ! $locked->status->equals(Pending::class)) {
                        return false;
                    }

                    $locked->touch();

                    $auditLogger->record(new AuditEntry(
                        action: AuditAction::VpsPlanChangeRequested,
                        outcome: AuditOutcome::Success,
                        subject: $locked,
                        tenantUserId: $locked->user_id,
                        visibility: 'admin',
                        context: [
                            'vps_plan_change_id' => $locked->id,
                            'public_id' => $locked->public_id,
                            'virtual_machine_id' => $locked->virtual_machine_id,
                            'reason' => 'stale_pending_redispatch',
                        ],
                        actorType: 'system',
                        actorLabel: 'vps:reconcile-plan-changes',
                    ));

                    ApplyVpsPlanChangeJob::dispatch($locked->id, $locked->virtual_machine_id)->afterCommit();

                    return true;
                });

                if ($dispatched) {
                    $count++;
                }
            } catch (Throwable $e) {
                Log::error(sprintf('Failed to re-dispatch stale pending plan change #%d: %s', $change->id, $e->getMessage()), [
                    'plan_change_id' => $change->id,
                    'exception' => $e,
                ]);
            }
        }

        return $count;
    }

    private function sweepStaleApplying(Carbon $cutoff, Carbon $now): int
    {
        $staleApplying = VpsPlanChange::query()
            ->where('status', Applying::$name)
            ->where(function (Builder $query) use ($cutoff): void {
                $query->where('updated_at', '<=', $cutoff)
                    ->orWhere('started_at', '<=', $cutoff);
            })
            ->where(function (Builder $query) use ($cutoff, $now): void {
                $query->whereNull('apply_deadline')
                    ->orWhere('apply_deadline', '<=', $now)
                    ->orWhere('updated_at', '<=', $cutoff);
            })
            ->get();

        $count = 0;

        foreach ($staleApplying as $change) {
            ReconcileVpsPlanChangeJob::dispatch($change->id, $change->virtual_machine_id);
            $count++;
        }

        return $count;
    }

    private function sweepExpiredAuthorizations(Carbon $cutoff, AuditLogger $auditLogger): int
    {
        $expired = VpsPlanChange::query()
            ->where('status', AwaitingAuthorization::$name)
            ->where(function (Builder $query) use ($cutoff): void {
                $query->where('updated_at', '<=', $cutoff)
                    ->orWhere('requested_at', '<=', $cutoff);
            })
            ->get();

        $count = 0;

        foreach ($expired as $change) {
            try {
                $cancelled = DB::transaction(function () use ($change, $auditLogger): bool {
                    /** @var VpsPlanChange|null $locked */
                    $locked = VpsPlanChange::query()
                        ->where('id', $change->id)
                        ->lockForUpdate()
                        ->first();

                    if ($locked === null || ! $locked->status->equals(AwaitingAuthorization::class)) {
                        return false;
                    }

                    $locked->stage = null;
                    $locked->error_code = 'AUTHORIZATION_TIMEOUT';
                    $locked->error_message = 'Plan change authorization timed out before external approval was received.';
                    $locked->status->transitionTo(Cancelled::class);

                    $auditLogger->record(new AuditEntry(
                        action: AuditAction::VpsPlanChangeCancelled,
                        outcome: AuditOutcome::Success,
                        subject: $locked,
                        tenantUserId: $locked->user_id,
                        visibility: 'tenant',
                        context: [
                            'vps_plan_change_id' => $locked->id,
                            'public_id' => $locked->public_id,
                            'virtual_machine_id' => $locked->virtual_machine_id,
                            'from_version_id' => $locked->from_version_id,
                            'to_version_id' => $locked->to_version_id,
                            'error_code' => 'AUTHORIZATION_TIMEOUT',
                            'reason' => 'authorization_timeout',
                        ],
                        actorType: 'system',
                        actorLabel: 'vps:reconcile-plan-changes',
                    ));

                    VpsPlanChangeCancelled::dispatch(
                        $locked->id,
                        $locked->public_id,
                        $locked->virtual_machine_id,
                        $locked->user_id,
                        $locked->from_version_id,
                        $locked->to_version_id,
                        null,
                    );

                    return true;
                });

                if ($cancelled) {
                    $count++;
                }
            } catch (Throwable $e) {
                Log::error(sprintf('Failed to expire awaiting_authorization plan change #%d: %s', $change->id, $e->getMessage()), [
                    'plan_change_id' => $change->id,
                    'exception' => $e,
                ]);
            }
        }

        return $count;
    }
}
