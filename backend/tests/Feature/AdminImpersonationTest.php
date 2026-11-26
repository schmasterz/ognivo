<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\AuditAction;
use App\Audit\AuditOutcome;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\VmLifecycleService;
use App\States\Vm\Stopped;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class AdminImpersonationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $otherAdmin;

    private User $activeTenant;

    private User $suspendedTenant;

    /** @var array<string, string> */
    private array $sessionCookies = [];

    private ?string $xsrfToken = null;

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

        // Reset browser session state
        $this->resetBrowserSession();
    }

    private function resetBrowserSession(): void
    {
        $this->sessionCookies = [];
        $this->xsrfToken = null;
        $this->app['auth']->forgetGuards();
        $this->withUnencryptedCookies([]);
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private function captureSessionCookies(TestResponse $response): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            $value = $cookie->getValue();
            if ($value !== null) {
                $this->sessionCookies[$cookie->getName()] = $value;
            }
        }

        $xsrf = $response->getCookie('XSRF-TOKEN')?->getValue();
        if (is_string($xsrf) && $xsrf !== '') {
            $this->xsrfToken = $xsrf;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function statefulRequest(string $method, string $uri, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        $headers = ['Origin' => 'http://localhost'];
        if ($this->xsrfToken !== null) {
            $headers['X-XSRF-TOKEN'] = $this->xsrfToken;
        }

        $response = $this->withCredentials()
            ->withUnencryptedCookies($this->sessionCookies)
            ->withHeaders($headers)
            ->json($method, $uri, $data);

        $this->captureSessionCookies($response);

        return $response;
    }

    /**
     * @return TestResponse<Response>
     */
    private function loginAsStatefulUser(User $user, string $password = 'password'): TestResponse
    {
        $this->resetBrowserSession();

        $csrfResponse = $this->withHeader('Origin', 'http://localhost')
            ->get('/sanctum/csrf-cookie');
        $csrfResponse->assertNoContent();
        $this->captureSessionCookies($csrfResponse);

        $loginResponse = $this->statefulRequest('POST', '/api/v1/login', [
            'email' => $user->email,
            'password' => $password,
        ]);
        $loginResponse->assertOk()->assertJsonMissingPath('token');

        return $loginResponse;
    }

    /**
     * @return TestResponse<Response>
     */
    private function startImpersonation(User $targetUser): TestResponse
    {
        return $this->statefulRequest('POST', "/api/v1/admin/users/{$targetUser->id}/impersonate");
    }

    public function test_bearer_tokens_are_rejected_for_authenticated_and_impersonation_routes(): void
    {
        $token = $this->admin->createToken('legacy-bearer')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me')
            ->assertStatus(401);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/admin/users/{$this->activeTenant->id}/impersonate")
            ->assertStatus(401);
    }

    public function test_non_admin_cannot_impersonate(): void
    {
        $this->loginAsStatefulUser($this->activeTenant);

        $this->startImpersonation($this->suspendedTenant)
            ->assertForbidden();
    }

    public function test_admin_cannot_impersonate_themself(): void
    {
        $this->loginAsStatefulUser($this->admin);

        $this->startImpersonation($this->admin)
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'Cannot impersonate yourself.']);
    }

    public function test_admin_cannot_impersonate_another_admin(): void
    {
        $this->loginAsStatefulUser($this->admin);

        $this->startImpersonation($this->otherAdmin)
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'Cannot impersonate another administrator.']);
    }

    public function test_admin_cannot_impersonate_inactive_user(): void
    {
        $this->loginAsStatefulUser($this->admin);

        $this->startImpersonation($this->suspendedTenant)
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'Cannot impersonate an inactive user.']);
    }

    public function test_admin_impersonate_establishes_session_and_records_audit_with_impersonator_id(): void
    {
        $this->loginAsStatefulUser($this->admin);

        $response = $this->startImpersonation($this->activeTenant)
            ->assertOk()
            ->assertJsonMissingPath('token')
            ->assertJsonStructure(['user', 'expires_at', 'message'])
            ->assertJsonPath('user.id', $this->activeTenant->id);

        $this->assertNotEmpty($response->json('expires_at'));

        // Verify impersonation.started audit row
        /** @var AuditLog|null $log */
        $log = AuditLog::where('action', AuditAction::ImpersonationStarted->value)->latest('id')->first();
        $this->assertNotNull($log);
        $outcomeVal = is_string($log->outcome) ? $log->outcome : $log->outcome->value;
        $this->assertSame(AuditOutcome::Success->value, $outcomeVal);
        $this->assertSame($this->admin->id, $log->impersonator_id);
        $this->assertSame($this->activeTenant->id, $log->tenant_user_id);
        $this->assertSame('tenant', $log->visibility);

        // Verify /me reflects impersonated user and active impersonation metadata
        $this->statefulRequest('GET', '/api/v1/me')
            ->assertOk()
            ->assertJsonPath('user.id', $this->activeTenant->id)
            ->assertJsonPath('impersonation.active', true)
            ->assertJsonPath('impersonation.impersonator_id', $this->admin->id)
            ->assertJsonPath('impersonation.impersonator_name', $this->admin->name);

        // Verify tenant sees this session in Activity tab (GET /api/v1/audit)
        $auditRes = $this->statefulRequest('GET', '/api/v1/audit')->assertOk();

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
        $this->loginAsStatefulUser($this->admin);
        $this->startImpersonation($this->activeTenant)->assertOk();

        // Execute profile update (permitted: updating name only)
        $this->statefulRequest('PUT', '/api/v1/me', [
            'name' => 'Alice Support Updated',
            'email' => 'alice@example.com',
        ])->assertOk();

        // Check user.updated audit log
        $updateLog = AuditLog::where('action', AuditAction::UserUpdated->value)->latest('id')->first();
        $this->assertNotNull($updateLog);
        $this->assertSame($this->admin->id, $updateLog->impersonator_id, 'impersonator_id must be populated on profile update');
        $this->assertSame($this->activeTenant->id, $updateLog->tenant_user_id);
    }

    public function test_forbidden_actions_are_rejected_and_audited_as_denied(): void
    {
        $this->loginAsStatefulUser($this->admin);
        $this->startImpersonation($this->activeTenant)->assertOk();

        // 1. Password changes: rejected and audited as denied
        $this->statefulRequest('PUT', '/api/v1/me', [
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'new-secret-password-1234',
            'password_confirmation' => 'new-secret-password-1234',
        ])->assertStatus(403);

        $passwordLog = AuditLog::where('action', AuditAction::AuthPasswordChanged->value)
            ->where('outcome', AuditOutcome::Denied->value)
            ->latest('id')
            ->first();
        $this->assertNotNull($passwordLog, 'Denied password change must be audited');
        $this->assertSame($this->admin->id, $passwordLog->impersonator_id);
        $this->assertSame($this->activeTenant->id, $passwordLog->tenant_user_id);

        // 2. Email changes: rejected and audited as denied
        $this->statefulRequest('PUT', '/api/v1/me', [
            'name' => 'Alice',
            'email' => 'alice-hacked@example.com',
        ])->assertStatus(403);

        $emailLog = AuditLog::where('action', AuditAction::AuthEmailChanged->value)
            ->where('outcome', AuditOutcome::Denied->value)
            ->latest('id')
            ->first();
        $this->assertNotNull($emailLog, 'Denied email change must be audited');
        $this->assertSame($this->admin->id, $emailLog->impersonator_id);
        $this->assertSame($this->activeTenant->id, $emailLog->tenant_user_id);

        // 3. Console ticket issuing: rejected and audited as denied
        $vm = VirtualMachine::factory()->create([
            'user_id' => $this->activeTenant->id,
            'name' => 'alice-vm',
            'status' => Stopped::class,
        ]);

        $this->statefulRequest('POST', "/api/v1/vms/{$vm->id}/console")
            ->assertStatus(403);

        $consolePostLog = AuditLog::where('action', AuditAction::VmActionDenied->value)
            ->where('outcome', AuditOutcome::Denied->value)
            ->latest('id')
            ->first();
        $this->assertNotNull($consolePostLog, 'Denied console request must be audited');
        $this->assertSame($this->admin->id, $consolePostLog->impersonator_id);

        // Also test directly on VmLifecycleService using active impersonation AuditContext
        try {
            app(VmLifecycleService::class)->issueConsoleTicket($vm, $this->activeTenant);
            $this->fail('Expected 403 HttpException');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        // 4. Deleting account: rejected and audited as denied
        $this->statefulRequest('DELETE', '/api/v1/me')
            ->assertStatus(403);

        $deleteLog = AuditLog::where('action', AuditAction::UserDeleted->value)
            ->where('outcome', AuditOutcome::Denied->value)
            ->latest('id')
            ->first();
        $this->assertNotNull($deleteLog, 'Denied account deletion must be audited');
        $this->assertSame($this->admin->id, $deleteLog->impersonator_id);
    }

    public function test_impersonation_stop_restores_admin_session_and_records_audit(): void
    {
        $this->loginAsStatefulUser($this->admin);
        $this->startImpersonation($this->activeTenant)->assertOk();

        // Impersonated session is active before stop
        $this->statefulRequest('GET', '/api/v1/me')
            ->assertOk()
            ->assertJsonPath('user.id', $this->activeTenant->id)
            ->assertJsonPath('impersonation.active', true);

        // Call stop
        $stopRes = $this->statefulRequest('POST', '/api/v1/impersonation/stop')
            ->assertOk()
            ->assertJsonMissingPath('token')
            ->assertJsonStructure(['user', 'message'])
            ->assertJsonPath('user.id', $this->admin->id);

        $this->assertSame($this->admin->id, $stopRes->json('user.id'));

        // Restored session belongs to the admin with impersonation cleared
        $this->statefulRequest('GET', '/api/v1/me')
            ->assertOk()
            ->assertJsonPath('user.id', $this->admin->id)
            ->assertJsonPath('impersonation', null);

        $this->statefulRequest('GET', '/api/v1/admin/users')->assertOk();

        // Audit row impersonation.ended is recorded with admin_session_issued context
        /** @var AuditLog|null $endLog */
        $endLog = AuditLog::where('action', AuditAction::ImpersonationEnded->value)->latest('id')->first();
        $this->assertNotNull($endLog);
        $outcomeVal = is_string($endLog->outcome) ? $endLog->outcome : $endLog->outcome->value;
        $this->assertSame(AuditOutcome::Success->value, $outcomeVal);
        $this->assertSame($this->admin->id, $endLog->impersonator_id);
        $this->assertSame($this->activeTenant->id, $endLog->tenant_user_id);
        $this->assertSame('tenant', $endLog->visibility);
        $this->assertTrue($endLog->context['admin_session_issued'] ?? false, 'Audit context must note admin session issued');

        // Stop cannot be called again once impersonation keys have been cleared
        $this->statefulRequest('POST', '/api/v1/impersonation/stop')
            ->assertStatus(403);
    }

    public function test_impersonation_stop_refuses_when_impersonator_demoted(): void
    {
        $this->loginAsStatefulUser($this->admin);
        $this->startImpersonation($this->activeTenant)->assertOk();

        // Demote admin to customer
        $this->admin->removeRole('admin');

        $this->statefulRequest('POST', '/api/v1/impersonation/stop')
            ->assertStatus(403)
            ->assertJsonMissingPath('token');

        $this->statefulRequest('GET', '/api/v1/me')
            ->assertOk()
            ->assertJsonPath('user.id', $this->activeTenant->id);
    }

    public function test_impersonation_stop_refuses_when_impersonator_suspended(): void
    {
        $this->loginAsStatefulUser($this->admin);
        $this->startImpersonation($this->activeTenant)->assertOk();

        // Suspend admin
        $this->admin->update(['status' => 'suspended']);

        $this->statefulRequest('POST', '/api/v1/impersonation/stop')
            ->assertStatus(403)
            ->assertJsonMissingPath('token');
    }

    public function test_impersonation_stop_refuses_when_impersonator_deleted(): void
    {
        $this->loginAsStatefulUser($this->admin);
        $this->startImpersonation($this->activeTenant)->assertOk();

        // Delete admin
        $this->admin->delete();

        $this->statefulRequest('POST', '/api/v1/impersonation/stop')
            ->assertStatus(403)
            ->assertJsonMissingPath('token');
    }

    public function test_non_impersonating_session_cannot_call_stop(): void
    {
        // Normal tenant browser session
        $this->loginAsStatefulUser($this->activeTenant);
        $this->statefulRequest('POST', '/api/v1/impersonation/stop')
            ->assertStatus(403);

        // Normal admin browser session
        $this->loginAsStatefulUser($this->admin);
        $this->statefulRequest('POST', '/api/v1/impersonation/stop')
            ->assertStatus(403);
    }

    public function test_impersonation_session_expires_after_30_minutes(): void
    {
        // Active before 30 minutes elapse
        $this->loginAsStatefulUser($this->admin);
        $this->startImpersonation($this->activeTenant)->assertOk();
        $this->statefulRequest('GET', '/api/v1/me')->assertOk();

        // Simulate impersonation started 31 minutes ago so impersonation_expires_at <= time()
        $this->loginAsStatefulUser($this->admin);
        Carbon::setTestNow(Carbon::createFromTimestamp(time() - (31 * 60)));
        try {
            $this->startImpersonation($this->activeTenant)->assertOk();
        } finally {
            Carbon::setTestNow();
        }

        $this->statefulRequest('GET', '/api/v1/me')
            ->assertStatus(401)
            ->assertJsonFragment(['message' => 'Impersonation session expired.']);

        // Session was invalidated on expiry
        $this->statefulRequest('GET', '/api/v1/me')
            ->assertStatus(401);
    }
}
