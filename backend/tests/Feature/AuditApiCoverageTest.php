<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\AuditContext;
use App\DTO\Audit\AuditFilterDTO;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditSealService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AuditApiCoverageTest extends TestCase
{
    use AuditAssertionTrait;
    use RefreshDatabase;

    private User $admin;

    private User $tenantA;

    private User $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $this->admin = User::factory()->create([
            'email' => 'admin@example.com',
            'status' => 'active',
        ]);
        $this->admin->assignRole('admin');

        $this->tenantA = User::factory()->create([
            'email' => 'tenant-a@example.com',
            'status' => 'active',
        ]);
        $this->tenantA->assignRole('customer');

        $this->tenantB = User::factory()->create([
            'email' => 'tenant-b@example.com',
            'status' => 'active',
        ]);
        $this->tenantB->assignRole('customer');

        app(AuditContext::class)->setRequestId('req-api-audit-test');
    }

    public function test_tenant_cannot_see_another_tenant_or_admin_only_rows_including_with_query_param(): void
    {
        // 1. Tenant A - tenant visibility
        $logA = AuditLog::create([
            'occurred_at' => Carbon::now()->subMinutes(5),
            'request_id' => 'req-a1',
            'actor_type' => 'user',
            'actor_id' => $this->tenantA->id,
            'actor_label' => $this->tenantA->email,
            'tenant_user_id' => $this->tenantA->id,
            'action' => 'vm.create.requested',
            'outcome' => 'success',
            'visibility' => 'tenant',
            'context' => ['name' => 'vm-tenant-a'],
        ]);

        // 2. Tenant A - admin only visibility
        AuditLog::create([
            'occurred_at' => Carbon::now()->subMinutes(4),
            'request_id' => 'req-a2',
            'actor_type' => 'user',
            'actor_id' => $this->admin->id,
            'actor_label' => $this->admin->email,
            'tenant_user_id' => $this->tenantA->id,
            'action' => 'user.updated',
            'outcome' => 'success',
            'visibility' => 'admin',
            'context' => ['detail' => 'admin-internal-note'],
        ]);

        // 3. Tenant B - tenant visibility
        AuditLog::create([
            'occurred_at' => Carbon::now()->subMinutes(3),
            'request_id' => 'req-b1',
            'actor_type' => 'user',
            'actor_id' => $this->tenantB->id,
            'actor_label' => $this->tenantB->email,
            'tenant_user_id' => $this->tenantB->id,
            'action' => 'vm.create.requested',
            'outcome' => 'success',
            'visibility' => 'tenant',
            'context' => ['name' => 'vm-tenant-b'],
        ]);

        // Tenant A standard query
        $response = $this->actingAs($this->tenantA)
            ->getJson('/api/v1/audit')
            ->assertOk();

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($logA->id, $data[0]['id']);

        // Tenant A maliciously attempts to query Tenant B's logs via query string
        $maliciousResponse = $this->actingAs($this->tenantA)
            ->getJson('/api/v1/audit?tenant_user_id='.$this->tenantB->id)
            ->assertOk();

        $maliciousData = $maliciousResponse->json('data');
        $this->assertCount(1, $maliciousData);
        $this->assertSame($logA->id, $maliciousData[0]['id']);
    }

    public function test_cursor_pagination_is_stable_while_rows_are_inserted(): void
    {
        $baseTime = Carbon::parse('2026-11-05 10:00:00');

        $rows = [];
        for ($i = 1; $i <= 5; $i++) {
            $rows[] = AuditLog::create([
                'occurred_at' => $baseTime->copy()->addMinutes($i),
                'actor_type' => 'user',
                'actor_id' => $this->tenantA->id,
                'tenant_user_id' => $this->tenantA->id,
                'action' => 'vm.action.requested',
                'outcome' => 'success',
                'visibility' => 'tenant',
                'context' => ['name' => "step-{$i}"],
            ]);
        }

        // Page 1: per_page=2 (should get row 5 and row 4)
        $res1 = $this->actingAs($this->tenantA)
            ->getJson('/api/v1/audit?per_page=2')
            ->assertOk();

        $data1 = $res1->json('data');
        $this->assertCount(2, $data1);
        $this->assertSame(2, $res1->json('per_page'));
        $this->assertSame(5, $res1->json('total'));
        $this->assertSame($rows[4]->id, $data1[0]['id']); // Row 5
        $this->assertSame($rows[3]->id, $data1[1]['id']); // Row 4

        $cursor1 = $res1->json('next_cursor');
        $this->assertNotNull($cursor1);

        // Concurrently insert a newer row (Row 6) and an older row (Row 0)
        AuditLog::create([
            'occurred_at' => $baseTime->copy()->addMinutes(10), // Newer
            'actor_type' => 'user',
            'actor_id' => $this->tenantA->id,
            'tenant_user_id' => $this->tenantA->id,
            'action' => 'vm.action.requested',
            'outcome' => 'success',
            'visibility' => 'tenant',
            'context' => ['name' => 'step-6'],
        ]);

        AuditLog::create([
            'occurred_at' => $baseTime->copy()->subMinutes(5), // Older
            'actor_type' => 'user',
            'actor_id' => $this->tenantA->id,
            'tenant_user_id' => $this->tenantA->id,
            'action' => 'vm.action.requested',
            'outcome' => 'success',
            'visibility' => 'tenant',
            'context' => ['name' => 'step-0'],
        ]);

        // Page 2 using cursor1: should get Row 3 and Row 2, completely stable
        $res2 = $this->actingAs($this->tenantA)
            ->getJson('/api/v1/audit?per_page=2&cursor='.urlencode((string) $cursor1))
            ->assertOk();

        $data2 = $res2->json('data');
        $this->assertCount(2, $data2);
        $this->assertSame($rows[2]->id, $data2[0]['id']); // Row 3
        $this->assertSame($rows[1]->id, $data2[1]['id']); // Row 2

        $cursor2 = $res2->json('next_cursor');
        $this->assertNotNull($cursor2);

        // Page 3 using cursor2: should get Row 1 and Row 0
        $res3 = $this->actingAs($this->tenantA)
            ->getJson('/api/v1/audit?per_page=2&cursor='.urlencode((string) $cursor2))
            ->assertOk();

        $data3 = $res3->json('data');
        $this->assertCount(2, $data3);
        $this->assertSame($rows[0]->id, $data3[0]['id']); // Row 1
        $this->assertSame('step-0', $data3[1]['context']['name']); // Row 0

        $cursor3 = $res3->json('next_cursor');
        $this->assertNull($cursor3);
    }

    public function test_export_streams_and_is_audited(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            AuditLog::create([
                'occurred_at' => Carbon::now()->subMinutes($i),
                'actor_type' => 'user',
                'actor_id' => $this->tenantA->id,
                'tenant_user_id' => $this->tenantA->id,
                'action' => 'vm.action.requested',
                'outcome' => 'success',
                'visibility' => 'tenant',
                'context' => ['vm' => "test-vm-{$i}"],
            ]);
        }

        // 1. NDJSON export
        $responseNdjson = $this->actingAs($this->admin)
            ->get('/api/v1/admin/audit/export');

        $responseNdjson->assertOk();
        $responseNdjson->assertHeader('Content-Type', 'application/x-ndjson; charset=UTF-8');

        $contentNdjson = $responseNdjson->streamedContent();
        $lines = array_filter(explode("\n", trim($contentNdjson)));
        $this->assertGreaterThanOrEqual(3, count($lines));

        $firstLine = json_decode($lines[0], true);
        $this->assertIsArray($firstLine);
        $this->assertArrayHasKey('id', $firstLine);
        $this->assertArrayHasKey('action', $firstLine);

        // Verify audit.exported event was logged for NDJSON
        $exportAuditRow = DB::table('audit_logs')
            ->where('action', 'audit.exported')
            ->latest('id')
            ->first();

        $this->assertNotNull($exportAuditRow);
        $this->assertSame('admin', $exportAuditRow->visibility);
        $ctx = json_decode((string) $exportAuditRow->context, true);
        $this->assertSame('ndjson', $ctx['format']);
        $this->assertGreaterThanOrEqual(3, $ctx['row_count']);

        // 2. CSV export
        $responseCsv = $this->actingAs($this->admin)
            ->get('/api/v1/admin/audit/export?format=csv');

        $responseCsv->assertOk();
        $responseCsv->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $contentCsv = $responseCsv->streamedContent();
        $csvLines = array_filter(explode("\n", trim($contentCsv)));
        $this->assertGreaterThanOrEqual(4, count($csvLines)); // header + at least 3 rows
        $this->assertStringContainsString('id,occurred_at,request_id', $csvLines[0]);

        // Verify audit.exported event was logged for CSV
        $exportAuditCsvRow = DB::table('audit_logs')
            ->where('action', 'audit.exported')
            ->latest('id')
            ->first();

        $this->assertNotNull($exportAuditCsvRow);
        $ctxCsv = json_decode((string) $exportAuditCsvRow->context, true);
        $this->assertSame('csv', $ctxCsv['format']);
    }

    public function test_export_and_verify_are_not_routed_to_show_id(): void
    {
        // Calling /api/admin/audit/export
        $resExport = $this->actingAs($this->admin)->get('/api/v1/admin/audit/export');
        $resExport->assertOk();
        $resExport->assertHeader('Content-Type', 'application/x-ndjson; charset=UTF-8');

        // Calling /api/admin/audit/verify
        $resVerify = $this->actingAs($this->admin)->getJson('/api/v1/admin/audit/verify');
        $resVerify->assertOk();
        $resVerify->assertJsonStructure(['ok', 'checked', 'first_failed_id']);

        // Calling non-numeric string /api/admin/audit/invalid-id should 404 (due to whereNumber)
        $resInvalid = $this->actingAs($this->admin)->getJson('/api/v1/admin/audit/invalid-id');
        $resInvalid->assertNotFound();

        // Calling existing numeric ID /api/admin/audit/{id}
        $log = AuditLog::create([
            'occurred_at' => Carbon::now(),
            'actor_type' => 'user',
            'actor_id' => $this->admin->id,
            'action' => 'settings.updated',
            'outcome' => 'success',
            'visibility' => 'admin',
        ]);

        $resShow = $this->actingAs($this->admin)->getJson("/api/v1/admin/audit/{$log->id}");
        $resShow->assertOk();
        $this->assertSame($log->id, $resShow->json('data.id'));
    }

    public function test_non_admin_gets_403_on_admin_routes(): void
    {
        $this->actingAs($this->tenantA)->getJson('/api/v1/admin/audit')->assertForbidden();
        $this->actingAs($this->tenantA)->get('/api/v1/admin/audit/export')->assertForbidden();
        $this->actingAs($this->tenantA)->getJson('/api/v1/admin/audit/verify')->assertForbidden();
        $this->actingAs($this->tenantA)->getJson('/api/v1/admin/audit/1')->assertForbidden();
    }

    public function test_unauthenticated_gets_401(): void
    {
        $this->getJson('/api/v1/audit')->assertUnauthorized();
        $this->getJson('/api/v1/admin/audit')->assertUnauthorized();
    }

    public function test_ipv4_and_ipv6_filters_work(): void
    {
        $v4 = '198.51.100.42';
        $v6 = '2001:db8:85a3::8a2e:370:7334';

        $logV4 = AuditLog::create([
            'occurred_at' => Carbon::now()->subMinutes(2),
            'actor_type' => 'user',
            'actor_id' => $this->tenantA->id,
            'action' => 'auth.login.success',
            'outcome' => 'success',
            'ip' => $v4,
            'visibility' => 'admin',
        ]);

        $logV6 = AuditLog::create([
            'occurred_at' => Carbon::now()->subMinutes(1),
            'actor_type' => 'user',
            'actor_id' => $this->tenantB->id,
            'action' => 'auth.login.success',
            'outcome' => 'success',
            'ip' => $v6,
            'visibility' => 'admin',
        ]);

        // Filter IPv4
        $resV4 = $this->actingAs($this->admin)->getJson('/api/v1/admin/audit?ip='.$v4)->assertOk();
        $this->assertSame(1, count($resV4->json('data')));
        $this->assertSame($logV4->id, $resV4->json('data.0.id'));
        $this->assertSame($v4, $resV4->json('data.0.ip'));

        // Filter IPv6
        $resV6 = $this->actingAs($this->admin)->getJson('/api/v1/admin/audit?ip='.urlencode($v6))->assertOk();
        $this->assertSame(1, count($resV6->json('data')));
        $this->assertSame($logV6->id, $resV6->json('data.0.id'));
        $this->assertSame($v6, $resV6->json('data.0.ip'));
    }

    public function test_tenant_resource_actor_rules_and_redaction(): void
    {
        // 1. Tenant action by user themselves
        $logSelf = AuditLog::create([
            'occurred_at' => Carbon::now()->subMinutes(5),
            'actor_type' => 'user',
            'actor_id' => $this->tenantA->id,
            'actor_label' => $this->tenantA->email,
            'tenant_user_id' => $this->tenantA->id,
            'action' => 'vm.create.requested',
            'outcome' => 'success',
            'visibility' => 'tenant',
            'ip' => '203.0.113.10',
            'user_agent' => 'TenantBrowser/1.0',
            'context' => [
                'name' => 'my-vm',
                'internal_admin_token' => 'secret-leak-test',
            ],
        ]);

        // 2. Admin action directly affecting tenant (without impersonation)
        $logAdmin = AuditLog::create([
            'occurred_at' => Carbon::now()->subMinutes(3),
            'actor_type' => 'user',
            'actor_id' => $this->admin->id,
            'actor_label' => $this->admin->email,
            'tenant_user_id' => $this->tenantA->id,
            'action' => 'vm.action.requested',
            'outcome' => 'success',
            'visibility' => 'tenant',
            'ip' => '192.168.1.100', // Admin IP - MUST NOT LEAK
            'user_agent' => 'AdminBrowser/2.0', // Admin User-Agent - MUST NOT LEAK
            'context' => [
                'action' => 'stop',
                'pii' => [
                    'attempted_email' => 'admin-target@example.com',
                ],
            ],
        ]);

        // 3. Admin action via impersonation
        $logImpersonated = AuditLog::create([
            'occurred_at' => Carbon::now()->subMinutes(1),
            'actor_type' => 'user',
            'actor_id' => $this->tenantA->id,
            'actor_label' => $this->tenantA->email,
            'impersonator_id' => $this->admin->id,
            'tenant_user_id' => $this->tenantA->id,
            'action' => 'vm.action.requested',
            'outcome' => 'success',
            'visibility' => 'tenant',
            'ip' => '10.0.0.1',
            'user_agent' => 'AdminImpersonator/1.0',
            'context' => ['action' => 'restart'],
        ]);

        $res = $this->actingAs($this->tenantA)->getJson('/api/v1/audit')->assertOk();
        $data = $res->json('data');
        $this->assertCount(3, $data);

        // Most recent first:
        // Item 0: Impersonated
        $this->assertSame($logImpersonated->id, $data[0]['id']);
        $this->assertSame('platform_admin', $data[0]['actor']['kind']);
        $this->assertNotEmpty($data[0]['actor']['impersonator']);
        $this->assertNull($data[0]['ip']); // No IP of admin
        $this->assertNull($data[0]['user_agent']); // No user agent of others

        // Item 1: Admin direct action
        $this->assertSame($logAdmin->id, $data[1]['id']);
        $this->assertSame('platform_admin', $data[1]['actor']['kind']);
        $this->assertArrayNotHasKey('impersonator', $data[1]['actor']);
        $this->assertNull($data[1]['ip']); // Admin IP redacted
        $this->assertNull($data[1]['user_agent']); // Admin user agent redacted
        $this->assertArrayNotHasKey('pii', $data[1]['context'] ?? []); // other person's PII filtered out

        // Item 2: Tenant own action
        $this->assertSame($logSelf->id, $data[2]['id']);
        $this->assertSame('you', $data[2]['actor']['kind']);
        $this->assertSame('203.0.113.10', $data[2]['ip']); // Tenant's own IP visible
        $this->assertSame('TenantBrowser/1.0', $data[2]['user_agent']); // Tenant's own UA visible
        $this->assertSame('my-vm', $data[2]['context']['name']);
        $this->assertArrayNotHasKey('internal_admin_token', $data[2]['context']); // non-whitelisted key stripped
    }

    public function test_verify_seals_endpoint(): void
    {
        $service = app(AuditSealService::class);

        // 1. No seals
        $resEmpty = $this->actingAs($this->admin)->getJson('/api/v1/admin/audit/verify')->assertOk();
        $this->assertTrue($resEmpty->json('ok'));
        $this->assertSame(0, $resEmpty->json('checked'));
        $this->assertNull($resEmpty->json('first_failed_id'));

        // 2. Create 2 valid seals
        $seal1 = $service->createSeal(1, 10, null, 'v1');
        $seal2 = $service->createSeal(11, 20, $seal1->hash, 'v1');

        $resValid = $this->actingAs($this->admin)->getJson('/api/v1/admin/audit/verify')->assertOk();
        $this->assertTrue($resValid->json('ok'));
        $this->assertSame(2, $resValid->json('checked'));
        $this->assertNull($resValid->json('first_failed_id'));

        // 3. Tamper with seal2
        $seal2->hash = str_repeat('a', 64);
        $seal2->save();

        $resTampered = $this->actingAs($this->admin)->getJson('/api/v1/admin/audit/verify')->assertOk();
        $this->assertFalse($resTampered->json('ok'));
        $this->assertSame($seal2->id, $resTampered->json('first_failed_id'));
    }

    public function test_all_dto_filters_work_on_admin_index(): void
    {
        $baseTime = Carbon::parse('2026-11-05 12:00:00');

        $logMatch = AuditLog::create([
            'occurred_at' => $baseTime,
            'request_id' => 'req-filter-match-123',
            'actor_type' => 'user',
            'actor_id' => $this->tenantA->id,
            'tenant_user_id' => $this->tenantA->id,
            'action' => 'vm.create.requested',
            'outcome' => 'success',
            'subject_type' => 'App\\Models\\VirtualMachine',
            'subject_id' => '42',
            'visibility' => 'admin',
        ]);

        $logOther = AuditLog::create([
            'occurred_at' => $baseTime->copy()->subHours(2),
            'request_id' => 'req-filter-other-456',
            'actor_type' => 'user',
            'actor_id' => $this->tenantB->id,
            'tenant_user_id' => $this->tenantB->id,
            'action' => 'auth.login.failed',
            'outcome' => 'failed',
            'subject_type' => 'App\\Models\\User',
            'subject_id' => '99',
            'visibility' => 'admin',
        ]);

        // 1. actor_id filter
        $res = $this->actingAs($this->admin)->getJson('/api/v1/admin/audit?actor_id='.$this->tenantA->id)->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame($logMatch->id, $res->json('data.0.id'));

        // 2. tenant_user_id filter
        $res = $this->actingAs($this->admin)->getJson('/api/v1/admin/audit?tenant_user_id='.$this->tenantB->id)->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame($logOther->id, $res->json('data.0.id'));

        // 3. category filter (first segment: 'vm')
        $res = $this->actingAs($this->admin)->getJson('/api/v1/admin/audit?category=vm')->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame($logMatch->id, $res->json('data.0.id'));

        // 4. action filter
        $res = $this->actingAs($this->admin)->getJson('/api/v1/admin/audit?action=auth.login.failed')->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame($logOther->id, $res->json('data.0.id'));

        // 5. outcome filter
        $res = $this->actingAs($this->admin)->getJson('/api/v1/admin/audit?outcome=failed')->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame($logOther->id, $res->json('data.0.id'));

        // 6. subject_type & subject_id filters
        $res = $this->actingAs($this->admin)->getJson('/api/v1/admin/audit?subject_type=App%5CModels%5CVirtualMachine&subject_id=42')->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame($logMatch->id, $res->json('data.0.id'));

        // 7. request_id filter
        $res = $this->actingAs($this->admin)->getJson('/api/v1/admin/audit?request_id=req-filter-match-123')->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame($logMatch->id, $res->json('data.0.id'));

        // 8. from & to dates (ISO format)
        $res = $this->actingAs($this->admin)->getJson('/api/v1/admin/audit?from=2026-11-05T11:00:00Z&to=2026-11-05T13:00:00Z')->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame($logMatch->id, $res->json('data.0.id'));

        // 9. per_page (valid max 100, default 25, > 100 returns 422)
        $resDefault = $this->actingAs($this->admin)->getJson('/api/v1/admin/audit')->assertOk();
        $this->assertLessThanOrEqual(25, count($resDefault->json('data')));

        $resMax = $this->actingAs($this->admin)->getJson('/api/v1/admin/audit?per_page=100')->assertOk();
        $this->assertLessThanOrEqual(100, count($resMax->json('data')));

        $this->actingAs($this->admin)->getJson('/api/v1/admin/audit?per_page=101')->assertUnprocessable();

        // Direct DTO clamps to 100
        $dtoClamped = AuditFilterDTO::fromArray(['per_page' => 999]);
        $this->assertSame(100, $dtoClamped->perPage);
    }
}
