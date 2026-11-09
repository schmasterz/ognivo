<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\AuditAction;
use App\Audit\AuditContext;
use App\Audit\AuditOutcome;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\VmLifecycleService;
use App\States\Vm\Stopped;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class AdminImpersonationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $otherAdmin;

    private User $activeTenant;

    private User $suspendedTenant;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin');
        Role::findOrCreate('customer');

        $this->admin = User::factory()->create([
            'email' => 'superadmin@example.com',
            'status' => 'active',
        ]);
        $this->admin->assignRole('admin');

        $this->otherAdmin = User::factory()->create([
            'email' => 'colleague.admin@example.com',
            'status' => 'active',
        ]);
        $this->otherAdmin->assignRole('admin');

        $this->activeTenant = User::factory()->create([
            'name' => 'Alice Tenant',
            'email' => 'alice@example.com',
            'status' => 'active',
        ]);
        $this->activeTenant->assignRole('customer');

        $this->suspendedTenant = User::factory()->create([
            'email' => 'suspended@example.com',
            'status' => 'suspended',
        ]);
        $this->suspendedTenant->assignRole('customer');

        // Reset audit context
        $this->app->instance(AuditContext::class, new AuditContext);
    }

    private function withTokenHeader(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    public function test_non_admin_cannot_impersonate(): void
    {
        $this->actingAs($this->activeTenant)
            ->postJson("/api/admin/users/{$this->suspendedTenant->id}/impersonate")
            ->assertForbidden();
    }

    public function test_admin_cannot_impersonate_themself(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$this->admin->id}/impersonate")
            ->assertStatus(403);
    }

    public function test_admin_cannot_impersonate_another_admin(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$this->otherAdmin->id}/impersonate")
            ->assertStatus(403);
    }

    public function test_admin_cannot_impersonate_inactive_user(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$this->suspendedTenant->id}/impersonate")
            ->assertStatus(403);
    }

    public function test_admin_impersonate_issues_token_and_records_audit_with_impersonator_id(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$this->activeTenant->id}/impersonate")
            ->assertOk();

        $token = $response->json('token');
        $this->assertNotEmpty($token);

        // Verify impersonation.started audit row
        /** @var AuditLog $log */
        $log = AuditLog::where('action', AuditAction::ImpersonationStarted->value)->latest('id')->first();
        $this->assertNotNull($log);
        $outcomeVal = is_string($log->outcome) ? $log->outcome : $log->outcome->value;
        $this->assertSame(AuditOutcome::Success->value, $outcomeVal);
        $this->assertSame($this->admin->id, $log->impersonator_id);
        $this->assertSame($this->activeTenant->id, $log->tenant_user_id);
        $this->assertSame('tenant', $log->visibility);

        // Verify tenant sees this session in Activity tab (GET /api/audit)
        $auditRes = $this->withTokenHeader($token)
            ->getJson('/api/audit')
            ->assertOk();

        $data = $auditRes->json('data');
        $this->assertNotEmpty($data);
        $impersonationRow = collect($data)->firstWhere('action', AuditAction::ImpersonationStarted->value);
        $this->assertNotNull($impersonationRow);
        $this->assertSame('platform_admin', $impersonationRow['actor']['kind']);
        $this->assertSame('Platform administrator (support session)', $impersonationRow['actor']['display_label']);
        $this->assertStringContainsString('superadmin@example.com', $impersonationRow['actor']['impersonator']);
    }

    public function test_impersonator_id_is_present_on_every_row_created_during_session(): void
    {
        $impersonateRes = $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$this->activeTenant->id}/impersonate")
            ->assertOk();

        $token = $impersonateRes->json('token');

        // Execute profile update (permitted: updating name only)
        $this->withTokenHeader($token)
            ->putJson('/api/me', [
                'name' => 'Alice Support Updated',
                'email' => 'alice@example.com',
            ])
            ->assertOk();

        // Check user.updated audit log
        $updateLog = AuditLog::where('action', AuditAction::UserUpdated->value)->latest('id')->first();
        $this->assertNotNull($updateLog);
        $this->assertSame($this->admin->id, $updateLog->impersonator_id, 'impersonator_id must be populated on profile update');
        $this->assertSame($this->activeTenant->id, $updateLog->tenant_user_id);
    }

    public function test_forbidden_actions_are_rejected_and_audited_as_denied(): void
    {
        $impersonateRes = $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$this->activeTenant->id}/impersonate")
            ->assertOk();

        $token = $impersonateRes->json('token');

        // 1. Password changes: rejected and audited as denied
        $this->withTokenHeader($token)
            ->putJson('/api/me', [
                'name' => 'Alice',
                'email' => 'alice@example.com',
                'password' => 'new-secret-password-1234',
                'password_confirmation' => 'new-secret-password-1234',
            ])
            ->assertStatus(403);

        $passwordLog = AuditLog::where('action', AuditAction::AuthPasswordChanged->value)
            ->where('outcome', AuditOutcome::Denied->value)
            ->latest('id')
            ->first();
        $this->assertNotNull($passwordLog, 'Denied password change must be audited');
        $this->assertSame($this->admin->id, $passwordLog->impersonator_id);
        $this->assertSame($this->activeTenant->id, $passwordLog->tenant_user_id);

        // 2. Email changes: rejected and audited as denied
        $this->withTokenHeader($token)
            ->putJson('/api/me', [
                'name' => 'Alice',
                'email' => 'alice-hacked@example.com',
            ])
            ->assertStatus(403);

        $emailLog = AuditLog::where('action', AuditAction::AuthEmailChanged->value)
            ->where('outcome', AuditOutcome::Denied->value)
            ->latest('id')
            ->first();
        $this->assertNotNull($emailLog, 'Denied email change must be audited');
        $this->assertSame($this->admin->id, $emailLog->impersonator_id);
        $this->assertSame($this->activeTenant->id, $emailLog->tenant_user_id);

        // 3. Creating API credentials: rejected and audited as denied
        $this->withTokenHeader($token)
            ->postJson('/api/tokens', [
                'name' => 'backdoor-token',
            ])
            ->assertStatus(403);

        $tokenCreateLog = AuditLog::where('action', AuditAction::AuthTokensRevoked->value)
            ->where('outcome', AuditOutcome::Denied->value)
            ->latest('id')
            ->first();
        $this->assertNotNull($tokenCreateLog, 'Denied token creation must be audited');
        $this->assertSame($this->admin->id, $tokenCreateLog->impersonator_id);

        // 4. Token management: rejected and audited as denied
        $this->withTokenHeader($token)
            ->getJson('/api/tokens')
            ->assertStatus(403);

        $this->withTokenHeader($token)
            ->deleteJson('/api/tokens')
            ->assertStatus(403);

        // 5. Console ticket issuing: rejected and audited as denied
        $vm = VirtualMachine::factory()->create([
            'user_id' => $this->activeTenant->id,
            'name' => 'alice-vm',
            'status' => Stopped::class,
        ]);

        $this->withTokenHeader($token)
            ->postJson("/api/vms/{$vm->id}/console-ticket")
            ->assertStatus(403);

        $consoleLog = AuditLog::where('action', AuditAction::VmActionDenied->value)
            ->where('outcome', AuditOutcome::Denied->value)
            ->latest('id')
            ->first();
        $this->assertNotNull($consoleLog, 'Denied console ticket must be audited');
        $this->assertSame($this->admin->id, $consoleLog->impersonator_id);

        // Also test directly on VmLifecycleService
        try {
            app(VmLifecycleService::class)->issueConsoleTicket($vm, $this->activeTenant->withAccessToken($this->activeTenant->tokens()->latest()->first()));
            $this->fail('Expected 403 HttpException');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        // 6. Deleting account: rejected and audited as denied
        $this->withTokenHeader($token)
            ->deleteJson('/api/me')
            ->assertStatus(403);

        $deleteLog = AuditLog::where('action', AuditAction::UserDeleted->value)
            ->where('outcome', AuditOutcome::Denied->value)
            ->latest('id')
            ->first();
        $this->assertNotNull($deleteLog, 'Denied account deletion must be audited');
        $this->assertSame($this->admin->id, $deleteLog->impersonator_id);
    }

    public function test_impersonation_stop_revokes_token_and_records_audit(): void
    {
        $impersonateRes = $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$this->activeTenant->id}/impersonate")
            ->assertOk();

        $token = $impersonateRes->json('token');

        // Request succeeds before stop
        $this->withTokenHeader($token)->getJson('/api/me')->assertOk();

        // Call stop
        $this->withTokenHeader($token)
            ->postJson('/api/impersonation/stop')
            ->assertOk();

        // Audit row impersonation.ended is recorded
        /** @var AuditLog $endLog */
        $endLog = AuditLog::where('action', AuditAction::ImpersonationEnded->value)->latest('id')->first();
        $this->assertNotNull($endLog);
        $outcomeVal = is_string($endLog->outcome) ? $endLog->outcome : $endLog->outcome->value;
        $this->assertSame(AuditOutcome::Success->value, $outcomeVal);
        $this->assertSame($this->admin->id, $endLog->impersonator_id);
        $this->assertSame($this->activeTenant->id, $endLog->tenant_user_id);
        $this->assertSame('tenant', $endLog->visibility);

        // Token cannot be used after stop
        $this->withTokenHeader($token)->getJson('/api/me')->assertStatus(401);
    }

    public function test_token_expires_after_30_minutes(): void
    {
        $impersonateRes = $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$this->activeTenant->id}/impersonate")
            ->assertOk();

        $token = $impersonateRes->json('token');

        // Active at minute 0
        $this->withTokenHeader($token)->getJson('/api/me')->assertOk();

        // Travel 31 minutes into the future
        Carbon::setTestNow(now()->addMinutes(31));

        // Token has expired
        $this->withTokenHeader($token)->getJson('/api/me')->assertStatus(401);

        Carbon::setTestNow();
    }
}
