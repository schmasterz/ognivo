<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AuditGrantsAndStrictModeTest extends TestCase
{
    private function guardAgainstMissingAuditGrants(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Integration test skipped: DB_CONNECTION is not mysql.');
        }

        try {
            DB::connection('audit')->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Integration test skipped: audit connection cannot connect: '.$e->getMessage());
        }
    }

    public function test_audit_grants_command_is_idempotent(): void
    {
        $this->guardAgainstMissingAuditGrants();

        // First run
        $exitCode1 = Artisan::call('audit:grants', ['--force' => true]);
        $this->assertSame(0, $exitCode1, 'First run of audit:grants must succeed with 0 exit code');

        // Second run (idempotency verification)
        $exitCode2 = Artisan::call('audit:grants', ['--force' => true]);
        $this->assertSame(0, $exitCode2, 'Second run of audit:grants must succeed identically without errors');
    }

    public function test_audit_writer_can_insert_and_select_but_cannot_update_or_delete(): void
    {
        $this->guardAgainstMissingAuditGrants();

        $auditDb = DB::connection('audit');

        // INSERT should succeed for audit_writer
        $now = Carbon::now()->format('Y-m-d H:i:s.u');
        $auditDb->table('audit_logs')->insert([
            'occurred_at' => $now,
            'request_id' => 'grant-test-uuid',
            'actor_type' => 'system',
            'action' => 'grant.test.event',
            'outcome' => 'success',
            'visibility' => 'admin',
        ]);

        // SELECT should succeed for audit_writer
        $record = $auditDb->table('audit_logs')->where('request_id', 'grant-test-uuid')->first();
        $this->assertNotNull($record, 'audit_writer must be able to SELECT from audit_logs');

        // UPDATE must fail with permission denied
        $updateBlocked = false;
        try {
            $auditDb->table('audit_logs')->where('request_id', 'grant-test-uuid')->update(['action' => 'tampered.action']);
        } catch (QueryException $e) {
            // MySQL error 1142: UPDATE command denied to user
            $this->assertStringContainsString('UPDATE command denied', $e->getMessage());
            $updateBlocked = true;
        }
        $this->assertTrue($updateBlocked, 'audit_writer must NOT be able to UPDATE audit_logs');

        // DELETE must fail with permission denied
        $deleteBlocked = false;
        try {
            $auditDb->table('audit_logs')->where('request_id', 'grant-test-uuid')->delete();
        } catch (QueryException $e) {
            // MySQL error 1142: DELETE command denied to user
            $this->assertStringContainsString('DELETE command denied', $e->getMessage());
            $deleteBlocked = true;
        }
        $this->assertTrue($deleteBlocked, 'audit_writer must NOT be able to DELETE audit_logs');

        // Cleanup using migration connection which holds administrative privileges
        DB::connection('migration')->table('audit_logs')->where('request_id', 'grant-test-uuid')->delete();
    }

    public function test_main_app_user_cannot_update_or_delete_audit_logs_in_strict_mode(): void
    {
        $this->guardAgainstMissingAuditGrants();

        $originalStrict = config('audit.strict_grants');
        try {
            config(['audit.strict_grants' => true]);
            $this->artisan('audit:grants', ['--force' => true])->assertSuccessful();

            $mainUser = (string) (config('database.connections.mysql.username') === 'root'
                ? env('DB_APP_USERNAME', 'ognivo_user')
                : config('database.connections.mysql.username', 'ognivo_user'));

            config([
                'database.connections.strict_main' => array_merge(config('database.connections.mysql'), [
                    'username' => $mainUser,
                    'password' => env('DB_PASSWORD', 'ed2742367bcb674aac56a2cbebb55a35'),
                ]),
            ]);
            DB::purge('strict_main');

            $mainDb = DB::connection('strict_main');

            // Insert a test row via migration user
            $now = Carbon::now()->format('Y-m-d H:i:s.u');
            DB::connection('migration')->table('audit_logs')->insert([
                'occurred_at' => $now,
                'request_id' => 'strict-test-uuid',
                'actor_type' => 'system',
                'action' => 'strict.test.event',
                'outcome' => 'success',
                'visibility' => 'admin',
            ]);

            // In strict mode, main user can SELECT
            $row = $mainDb->table('audit_logs')->where('request_id', 'strict-test-uuid')->first();
            $this->assertNotNull($row);

            // In strict mode, UPDATE must fail with permission denied
            $updateBlocked = false;
            try {
                $mainDb->table('audit_logs')->where('request_id', 'strict-test-uuid')->update(['action' => 'tampered']);
            } catch (QueryException $e) {
                $this->assertStringContainsString('UPDATE command denied', $e->getMessage());
                $updateBlocked = true;
            }
            $this->assertTrue($updateBlocked, 'Main app user in strict mode must NOT be able to UPDATE audit_logs');

            // In strict mode, DELETE must fail with permission denied
            $deleteBlocked = false;
            try {
                $mainDb->table('audit_logs')->where('request_id', 'strict-test-uuid')->delete();
            } catch (QueryException $e) {
                $this->assertStringContainsString('DELETE command denied', $e->getMessage());
                $deleteBlocked = true;
            }
            $this->assertTrue($deleteBlocked, 'Main app user in strict mode must NOT be able to DELETE audit_logs');
        } finally {
            // Restore original state
            config(['audit.strict_grants' => $originalStrict]);
            try {
                DB::connection('migration')->table('audit_logs')->where('request_id', 'strict-test-uuid')->delete();
            } catch (\Throwable) {
            }

            if (! $originalStrict) {
                $mainUser = (string) config('database.connections.mysql.username', 'ognivo_user');
                $dbName = DB::connection('migration')->getDatabaseName();
                try {
                    DB::connection('migration')->statement("GRANT ALL PRIVILEGES ON `{$dbName}`.* TO '{$mainUser}'@'%'");
                } catch (\Throwable) {
                }
                Artisan::call('audit:grants', ['--force' => true]);
            }
        }
    }
}
