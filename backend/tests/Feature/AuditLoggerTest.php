<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\AuditAction;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Audit\AuditOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AuditLoggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_joins_and_rolls_back_with_surrounding_transaction(): void
    {
        $logger = app(AuditLogger::class);

        $entry = new AuditEntry(
            action: AuditAction::UserCreated,
            outcome: AuditOutcome::Success,
            subject: ['type' => 'user', 'id' => 999, 'label' => 'Test User'],
            tenantUserId: 999,
            visibility: 'admin',
            context: ['detail' => 'joined_tx_test'],
        );

        DB::beginTransaction();

        $logger->record($entry);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.created',
            'tenant_user_id' => 999,
        ]);

        DB::rollBack();

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'user.created',
            'tenant_user_id' => 999,
        ]);
    }

    public function test_record_independent_persists_even_when_outer_transaction_rolls_back(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Skipped on non-MySQL default connection.');
        }

        try {
            DB::connection('audit')->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Skipped: audit connection unavailable: '.$e->getMessage());
        }

        $logger = app(AuditLogger::class);

        $successEntry = new AuditEntry(
            action: AuditAction::UserCreated,
            outcome: AuditOutcome::Success,
            tenantUserId: 888,
            context: ['type' => 'normal_tx'],
        );

        $deniedEntry = new AuditEntry(
            action: AuditAction::AuthLoginFailed,
            outcome: AuditOutcome::Denied,
            tenantUserId: 888,
            context: ['type' => 'independent_tx'],
            pii: ['attempted_email' => 'hacker@example.com'],
        );

        DB::beginTransaction();

        $logger->record($successEntry);
        $logger->recordIndependent($deniedEntry);

        // Both are visible before rollback
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.created', 'tenant_user_id' => 888]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login.failed', 'tenant_user_id' => 888]);

        DB::rollBack();

        // Normal record should be gone, independent record must persist
        $this->assertDatabaseMissing('audit_logs', ['action' => 'user.created', 'tenant_user_id' => 888]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login.failed', 'tenant_user_id' => 888]);

        // Clean up persisted row using migration connection
        DB::connection('migration')->table('audit_logs')->where('tenant_user_id', 888)->delete();
    }

    public function test_record_independent_never_throws_on_failure(): void
    {
        // Temporarily configure an invalid database connection for audit
        config(['database.connections.audit.database' => 'non_existent_database_xyz_123']);
        DB::purge('audit');

        $logger = app(AuditLogger::class);

        $entry = new AuditEntry(
            action: AuditAction::AuthLoginFailed,
            outcome: AuditOutcome::Failed,
            context: ['reason' => 'failure_tolerance_test'],
        );

        // Must not throw an exception
        $logger->recordIndependent($entry);
        $this->assertTrue(true, 'recordIndependent executed cleanly without throwing');
    }
}
