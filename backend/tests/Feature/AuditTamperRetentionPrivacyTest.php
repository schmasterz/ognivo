<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\AuditAction;
use App\Jobs\DeleteUserWorkloadsJob;
use App\Mail\AuditVerificationFailedMail;
use App\Models\AuditLog;
use App\Models\AuditSeal;
use App\Models\User;
use App\Services\AuditSealService;
use App\Services\Hypervisor\HypervisorManager;
use App\Services\VmLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AuditTamperRetentionPrivacyTest extends TestCase
{
    use AuditAssertionTrait;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        config([
            'audit.hmac.current_key_id' => 'v1',
            'audit.hmac.current_key' => '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
            'audit.hmac.keys' => [
                'v1' => '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
            ],
            'audit.pseudonym_salt' => 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789',
            'audit.seal_delay_seconds' => 120,
            'audit.retention_days' => 400,
            'audit.attempted_email_days' => 30,
        ]);

        // Clean up any test archive files
        $dir = storage_path('app/audit-archive');
        if (File::isDirectory($dir)) {
            File::deleteDirectory($dir);
        }
    }

    protected function tearDown(): void
    {
        $dir = storage_path('app/audit-archive');
        if (File::isDirectory($dir)) {
            File::deleteDirectory($dir);
        }

        parent::tearDown();
    }

    public function test_modifying_sealed_non_personal_field_makes_verify_fail(): void
    {
        Mail::fake();

        $past = Carbon::now()->subSeconds(300);

        $log = AuditLog::create([
            'occurred_at' => $past,
            'request_id' => 'req-tamper-1',
            'actor_type' => 'user',
            'actor_id' => 10,
            'actor_label' => 'user@example.com',
            'action' => 'vm.create.requested',
            'outcome' => 'success',
            'subject_type' => 'vm',
            'subject_id' => '1',
            'subject_label' => 'test-vm',
            'ip' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'visibility' => 'tenant',
            'context' => ['name' => 'test-vm'],
        ]);

        // 1. Seal logs
        $exitSeal = Artisan::call('audit:seal');
        $this->assertSame(0, $exitSeal);
        $this->assertDatabaseCount('audit_seals', 1);

        // 2. Verification passes initially
        $exitVerify1 = Artisan::call('audit:verify');
        $this->assertSame(0, $exitVerify1);

        // 3. Tamper with a non-personal field (action)
        $log->action = 'vm.action.requested';
        $log->save();

        // 4. Verification must fail with non-zero exit code
        $exitVerify2 = Artisan::call('audit:verify');
        $this->assertSame(1, $exitVerify2);

        $output = Artisan::output();
        $this->assertStringContainsString('first_failed_id: 1', $output);
    }

    public function test_changing_only_personal_fields_does_not_fail_verify(): void
    {
        $past = Carbon::now()->subSeconds(300);

        $log = AuditLog::create([
            'occurred_at' => $past,
            'request_id' => 'req-pii-test',
            'actor_type' => 'user',
            'actor_id' => 10,
            'actor_label' => 'original.user@example.com',
            'action' => 'vm.create.requested',
            'outcome' => 'success',
            'subject_type' => 'vm',
            'subject_id' => '1',
            'subject_label' => 'original-vm-label',
            'ip' => '10.0.0.1',
            'user_agent' => 'OriginalAgent/1.0',
            'visibility' => 'tenant',
            'context' => [
                'name' => 'test-vm',
                'pii' => ['email' => 'original.user@example.com'],
            ],
        ]);

        Artisan::call('audit:seal');
        $this->assertDatabaseCount('audit_seals', 1);

        // Mutate only personal fields
        $log->actor_label = 'mutated.user@example.com';
        $log->subject_label = 'mutated-vm-label';
        $log->ip = '10.99.99.99';
        $log->user_agent = 'MutatedAgent/2.0';
        $log->context = [
            'name' => 'test-vm',
            'pii' => ['email' => 'mutated.user@example.com', 'pseudonymised' => true],
        ];
        $log->save();

        // Verification must still pass!
        $exitCode = Artisan::call('audit:verify');
        $this->assertSame(0, $exitCode, 'Verify must pass when only personal fields are modified.');
    }

    public function test_sealing_is_idempotent_ordered_and_never_skips_row_newer_than_delay(): void
    {
        $now = Carbon::now();

        // Row 1: 300s old (eligible)
        $row1 = AuditLog::create([
            'occurred_at' => $now->copy()->subSeconds(300),
            'action' => 'auth.login.success',
            'actor_type' => 'system',
            'outcome' => 'success',
            'visibility' => 'admin',
        ]);

        // Row 2: 30s old (newer than 120s delay cutoff - MUST STOP HERE)
        $row2 = AuditLog::create([
            'occurred_at' => $now->copy()->subSeconds(30),
            'action' => 'auth.login.success',
            'actor_type' => 'system',
            'outcome' => 'success',
            'visibility' => 'admin',
        ]);

        // Row 3: 400s old (older than delay, but higher id than Row 2 - MUST NOT BE SEALED)
        $row3 = AuditLog::create([
            'occurred_at' => $now->copy()->subSeconds(400),
            'action' => 'auth.login.success',
            'actor_type' => 'system',
            'outcome' => 'success',
            'visibility' => 'admin',
        ]);

        // Run audit:seal
        $exit1 = Artisan::call('audit:seal');
        $this->assertSame(0, $exit1);

        // Only row 1 should be sealed
        $seal1 = AuditSeal::first();
        $this->assertNotNull($seal1);
        $this->assertSame($row1->id, $seal1->from_id);
        $this->assertSame($row1->id, $seal1->to_id);

        // Run audit:seal again immediately (idempotency test)
        $exit2 = Artisan::call('audit:seal');
        $this->assertSame(0, $exit2);
        $this->assertDatabaseCount('audit_seals', 1);

        // Verify seals pass
        $exitVerify = Artisan::call('audit:verify');
        $this->assertSame(0, $exitVerify);
    }

    public function test_prune_exports_before_deleting_and_marks_seals_archived(): void
    {
        $oldDate = Carbon::now()->subDays(450);
        $month = $oldDate->format('Y-m');

        $row1 = AuditLog::create([
            'occurred_at' => $oldDate->copy()->addMinutes(1),
            'action' => 'user.created',
            'actor_type' => 'user',
            'actor_id' => 1,
            'actor_label' => 'admin@example.com',
            'outcome' => 'success',
            'visibility' => 'admin',
            'context' => ['sample' => 'data1'],
        ]);

        $row2 = AuditLog::create([
            'occurred_at' => $oldDate->copy()->addMinutes(2),
            'action' => 'vm.create.requested',
            'actor_type' => 'user',
            'actor_id' => 1,
            'actor_label' => 'admin@example.com',
            'outcome' => 'success',
            'visibility' => 'admin',
            'context' => ['sample' => 'data2'],
        ]);

        // Seal the rows
        Artisan::call('audit:seal');
        $seal = AuditSeal::first();
        $this->assertNotNull($seal);
        $this->assertFalse($seal->archived);
        $this->assertNull($seal->archive_sha256);

        // Run prune
        $exitPrune = Artisan::call('audit:prune');
        $this->assertSame(0, $exitPrune);

        // Verify export file was created
        $archivePath = storage_path("app/audit-archive/{$month}.ndjson.gz");
        $this->assertFileExists($archivePath);

        $computedSha = hash_file('sha256', $archivePath);

        // Verify decompressed content contains NDJSON lines
        $gz = gzopen($archivePath, 'rb');
        $decompressed = '';
        while (! gzeof($gz)) {
            $decompressed .= gzread($gz, 4096);
        }
        gzclose($gz);

        $lines = array_filter(explode("\n", trim($decompressed)));
        $this->assertCount(2, $lines);
        $decoded1 = json_decode($lines[0], true);
        $this->assertSame($row1->id, $decoded1['id']);
        $this->assertSame('user.created', $decoded1['action']);

        // Check covering seal is marked archived=1 with archive_sha256
        $seal->refresh();
        $this->assertTrue($seal->archived);
        $this->assertSame($computedSha, $seal->archive_sha256);

        // Check rows in audit_logs are deleted
        $this->assertDatabaseMissing('audit_logs', ['id' => $row1->id]);
        $this->assertDatabaseMissing('audit_logs', ['id' => $row2->id]);

        // Check audit_seals row is KEPT
        $this->assertDatabaseHas('audit_seals', ['id' => $seal->id]);
    }

    public function test_verify_still_passes_after_prune(): void
    {
        $oldDate = Carbon::now()->subDays(450);

        AuditLog::create([
            'occurred_at' => $oldDate,
            'action' => 'vm.create.requested',
            'actor_type' => 'system',
            'outcome' => 'success',
            'visibility' => 'admin',
        ]);

        Artisan::call('audit:seal');
        Artisan::call('audit:prune');

        // Verify should check hash linkage and HMAC for archived seals, skipping row recomputation
        $exitVerify = Artisan::call('audit:verify');
        $this->assertSame(0, $exitVerify, 'Verify must still pass after prune.');
    }

    public function test_verify_passes_after_pseudonymisation(): void
    {
        $user = User::factory()->create([
            'name' => 'Alice Tenant',
            'email' => 'alice@example.com',
            'status' => 'active',
        ]);

        $past = Carbon::now()->subSeconds(300);

        // Row 1: user is actor
        $row1 = AuditLog::create([
            'occurred_at' => $past->copy()->addSecond(),
            'action' => 'vm.action.requested',
            'actor_type' => 'user',
            'actor_id' => $user->id,
            'actor_label' => $user->name,
            'tenant_user_id' => $user->id,
            'outcome' => 'success',
            'subject_type' => 'vm',
            'subject_id' => '100',
            'subject_label' => 'alice-vm',
            'ip' => '192.168.10.5',
            'user_agent' => 'TenantBrowser/1.0',
            'visibility' => 'tenant',
            'context' => [
                'action' => 'start',
                'pii' => ['email' => $user->email, 'name' => $user->name],
            ],
        ]);

        // Row 2: user is subject
        $row2 = AuditLog::create([
            'occurred_at' => $past->copy()->addSeconds(2),
            'action' => 'user.updated',
            'actor_type' => 'system',
            'actor_id' => null,
            'actor_label' => null,
            'tenant_user_id' => $user->id,
            'outcome' => 'success',
            'subject_type' => 'user',
            'subject_id' => (string) $user->id,
            'subject_label' => $user->name,
            'ip' => '192.168.10.5',
            'user_agent' => 'TenantBrowser/1.0',
            'visibility' => 'tenant',
            'context' => [
                'pii' => ['email' => $user->email],
            ],
        ]);

        // Seal the rows
        Artisan::call('audit:seal');
        $this->assertDatabaseCount('audit_seals', 1);

        // Verify before pseudonymisation
        $this->assertSame(0, Artisan::call('audit:verify'));

        // Run pseudonymisation
        $exitPseudo = Artisan::call('audit:pseudonymise', ['userId' => $user->id]);
        $this->assertSame(0, $exitPseudo);

        $salt = (string) config('audit.pseudonym_salt');
        $expectedHash = substr(hash('sha256', $user->id.':'.$salt), 0, 8);
        $expectedPseudonym = "user-{$expectedHash}";

        // Inspect row 1
        $row1->refresh();
        $this->assertSame($expectedPseudonym, $row1->actor_label);
        $this->assertNull($row1->ip);
        $this->assertNull($row1->user_agent);
        $ctx1 = $row1->context;
        $this->assertSame(['pseudonymised' => true], $ctx1['pii']);

        // Inspect row 2
        $row2->refresh();
        $this->assertSame($expectedPseudonym, $row2->subject_label);
        $this->assertNull($row2->ip);
        $this->assertNull($row2->user_agent);
        $ctx2 = $row2->context;
        $this->assertSame(['pseudonymised' => true], $ctx2['pii']);

        // Verify passes after pseudonymisation
        $exitVerifyAfter = Artisan::call('audit:verify');
        $this->assertSame(0, $exitVerifyAfter, 'Verify must pass after user pseudonymisation.');

        // Records its own audit entry
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::UserPseudonymised->value,
            'outcome' => 'success',
        ]);
    }

    public function test_attempted_email_is_removed_after_configured_days(): void
    {
        $oldDate = Carbon::now()->subDays(35);
        $recentDate = Carbon::now()->subDays(10);

        // Older than 30 days
        $oldLog = AuditLog::create([
            'occurred_at' => $oldDate,
            'action' => 'auth.login.failed',
            'actor_type' => 'user',
            'outcome' => 'failed',
            'visibility' => 'admin',
            'context' => [
                'reason' => 'invalid_credentials',
                'pii' => ['attempted_email' => 'old_attempt@example.com'],
            ],
        ]);

        // Newer than 30 days
        $recentLog = AuditLog::create([
            'occurred_at' => $recentDate,
            'action' => 'auth.login.failed',
            'actor_type' => 'user',
            'outcome' => 'failed',
            'visibility' => 'admin',
            'context' => [
                'reason' => 'invalid_credentials',
                'pii' => ['attempted_email' => 'recent_attempt@example.com'],
            ],
        ]);

        // Run prune
        Artisan::call('audit:prune');

        $oldLog->refresh();
        $recentLog->refresh();

        $oldContext = $oldLog->context;
        $this->assertArrayNotHasKey('attempted_email', $oldContext['pii'] ?? []);

        $recentContext = $recentLog->context;
        $this->assertSame('recent_attempt@example.com', $recentContext['pii']['attempted_email']);
    }

    public function test_seal_signed_with_old_key_id_still_verifies(): void
    {
        $oldKey = 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff';
        $currentKey = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

        config([
            'audit.hmac.current_key_id' => 'v2',
            'audit.hmac.current_key' => $currentKey,
            'audit.hmac.keys' => [
                'v0' => $oldKey,
                'v2' => $currentKey,
            ],
        ]);

        $past = Carbon::now()->subSeconds(300);
        $row = AuditLog::create([
            'occurred_at' => $past,
            'action' => 'host.created',
            'actor_type' => 'system',
            'outcome' => 'success',
            'visibility' => 'admin',
        ]);

        /** @var AuditSealService $service */
        $service = app(AuditSealService::class);

        // Create seal signed with old key 'v0'
        $seal = $service->createSeal($row->id, $row->id, null, 'v0');
        $this->assertSame('v0', $seal->key_id);

        // Verify seals using current config where v0 is present in hmac.keys
        $result = $service->verifySeals();
        $this->assertTrue($result['ok'], 'Seal signed with historical key ID v0 must verify successfully.');
        $this->assertSame(1, $result['checked']);

        // CLI verify should also pass
        $exitVerify = Artisan::call('audit:verify');
        $this->assertSame(0, $exitVerify);
    }

    public function test_delete_user_workloads_job_calls_pseudonymise_command(): void
    {
        $user = User::factory()->create([
            'name' => 'Delete Me User',
            'email' => 'delete.me@example.com',
            'status' => 'deleting',
        ]);

        $log = AuditLog::create([
            'occurred_at' => Carbon::now()->subMinutes(5),
            'action' => 'user.updated',
            'actor_type' => 'user',
            'actor_id' => $user->id,
            'actor_label' => $user->name,
            'tenant_user_id' => $user->id,
            'outcome' => 'success',
            'subject_type' => 'user',
            'subject_id' => (string) $user->id,
            'subject_label' => $user->name,
            'ip' => '1.2.3.4',
            'user_agent' => 'TestBrowser',
            'visibility' => 'tenant',
            'context' => ['pii' => ['email' => $user->email]],
        ]);

        $job = new DeleteUserWorkloadsJob($user);
        $job->handle(
            app(VmLifecycleService::class),
            app(HypervisorManager::class)
        );

        $log->refresh();
        $salt = (string) config('audit.pseudonym_salt');
        $expectedPseudonym = 'user-'.substr(hash('sha256', $user->id.':'.$salt), 0, 8);

        $this->assertSame($expectedPseudonym, $log->actor_label);
        $this->assertSame($expectedPseudonym, $log->subject_label);
        $this->assertNull($log->ip);
        $this->assertNull($log->user_agent);
        $this->assertSame(['pseudonymised' => true], $log->context['pii']);
    }

    public function test_verify_failure_logs_critical_and_notifies_admins_by_mail(): void
    {
        Mail::fake();
        Log::shouldReceive('critical')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'failed verification'));
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        $admin = User::factory()->create([
            'name' => 'Root Admin',
            'email' => 'root.admin@example.com',
            'status' => 'active',
        ]);
        $admin->assignRole('admin');

        $log = AuditLog::create([
            'occurred_at' => Carbon::now()->subMinutes(5),
            'action' => 'user.updated',
            'actor_type' => 'user',
            'actor_id' => 1,
            'outcome' => 'success',
            'visibility' => 'admin',
        ]);

        Artisan::call('audit:seal');

        // Tamper with seal
        $seal = AuditSeal::first();
        $seal->hash = str_repeat('0', 64);
        $seal->save();

        $exitVerify = Artisan::call('audit:verify');
        $this->assertSame(1, $exitVerify);

        Mail::assertSent(AuditVerificationFailedMail::class, function ($mail) use ($admin, $seal) {
            return $mail->hasTo($admin->email) && $mail->firstFailedId === $seal->id;
        });
    }
}
