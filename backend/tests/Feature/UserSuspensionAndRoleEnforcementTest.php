<?php

declare(strict_types=1);

use App\DTO\User\UpdateUserDTO;
use App\Models\Host;
use App\Models\User;
use App\Models\UserQuota;
use App\Models\VirtualMachine;
use App\Services\UserService;
use App\Services\VmProvisioningService;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Establish a stateful browser cookie session and return stateful request headers.
 *
 * @return array<string, string>
 */
function establishStatefulCustomerSession(TestCase $test, string $email, string $password = 'password'): array
{
    $csrfRes = $test->withCredentials()
        ->withHeaders(['Origin' => 'http://localhost'])
        ->getJson('/sanctum/csrf-cookie');
    $csrfRes->assertNoContent();

    $xsrfCookie = (string) $csrfRes->getCookie('XSRF-TOKEN', false);
    $sessionCookieName = (string) config('session.cookie');
    $sessionCookie = (string) $csrfRes->getCookie($sessionCookieName, false);

    $loginRes = $test->withCredentials()
        ->withUnencryptedCookies([
            'XSRF-TOKEN' => $xsrfCookie,
            $sessionCookieName => $sessionCookie,
        ])
        ->withHeaders([
            'Origin' => 'http://localhost',
            'X-XSRF-TOKEN' => $xsrfCookie,
        ])
        ->postJson('/api/v1/login', [
            'email' => $email,
            'password' => $password,
        ]);

    $loginRes->assertOk();

    $newSessionCookie = (string) $loginRes->getCookie($sessionCookieName, false);
    $newXsrfCookie = $loginRes->getCookie('XSRF-TOKEN', false);
    if (is_string($newXsrfCookie) && $newXsrfCookie !== '') {
        $xsrfCookie = $newXsrfCookie;
    }

    $test->withCredentials()->withUnencryptedCookies([
        'XSRF-TOKEN' => $xsrfCookie,
        $sessionCookieName => $newSessionCookie,
    ]);
    app('auth')->forgetGuards();

    return [
        'Origin' => 'http://localhost',
        'Accept' => 'application/json',
        'X-XSRF-TOKEN' => $xsrfCookie,
    ];
}

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

    $this->admin = User::factory()->create([
        'name' => 'Root Admin',
        'email' => 'root-admin@test.local',
        'status' => 'active',
    ]);
    $this->admin->assignRole('admin');

    $this->customer = User::factory()->create([
        'name' => 'Active Customer',
        'email' => 'active-customer@test.local',
        'status' => 'active',
    ]);
    $this->customer->assignRole('customer');

    UserQuota::create([
        'user_id' => $this->customer->id,
        'max_vms' => 5,
        'max_cpu_cores' => 10,
        'max_ram_mb' => 10240,
        'max_storage_gb' => 100,
        'max_ips' => 5,
    ]);

    $this->host = Host::firstOrCreate(
        ['name' => 'eu-west-1'],
        [
            'endpoint_url' => 'https://127.0.0.1:8443',
            'driver' => 'fake',
            'is_active' => true,
        ]
    );
});

test('suspended user with an active session gets 403 on /api/vms (GET and POST) and cannot dispatch actions', function (): void {
    // 1. Establish stateful browser session while active
    $headers = establishStatefulCustomerSession($this, $this->customer->email);

    // Create a VM belonging to the customer
    $vm = VirtualMachine::create([
        'user_id' => $this->customer->id,
        'host_id' => $this->host->id,
        'name' => 'suspended-target-vm',
        'incus_project' => 'proj-susp-1',
        'instance_type' => 'virtual-machine',
        'status' => 'running',
        'cpu_cores' => 1,
        'ram_mb' => 1024,
        'disk_gb' => 10,
        'os_image' => 'ubuntu:24.04',
    ]);

    // 2. Suspend the customer
    $this->customer->update(['status' => 'suspended']);
    $this->app['auth']->forgetGuards();

    // GET /api/vms must return 403 with account_suspended message
    $getRes = $this->withHeaders($headers)->getJson('/api/v1/vms');
    $getRes->assertStatus(403);
    $getRes->assertJsonFragment(['message' => __('messages.auth.account_suspended')]);

    // POST /api/vms must return 403
    $postRes = $this->withHeaders($headers)->postJson('/api/v1/vms', [
        'name' => 'new-vm-blocked',
        'instance_type' => 'virtual-machine',
        'os_image' => 'ubuntu:24.04',
        'cpu_cores' => 1,
        'ram_mb' => 1024,
        'disk_gb' => 10,
    ]);
    $postRes->assertStatus(403);
    $postRes->assertJsonFragment(['message' => __('messages.auth.account_suspended')]);

    // POST /api/vms/{vm}/action must return 403
    $actionRes = $this->withHeaders($headers)->postJson("/api/v1/vms/{$vm->id}/action", [
        'action' => 'stop',
    ]);
    $actionRes->assertStatus(403);
    $actionRes->assertJsonFragment(['message' => __('messages.auth.account_suspended')]);
});

test('GET /api/me and POST /api/logout remain accessible for suspended users with active session', function (): void {
    $headers = establishStatefulCustomerSession($this, $this->customer->email);
    $this->customer->update(['status' => 'suspended']);
    $this->app['auth']->forgetGuards();

    // GET /api/me is exempt from EnsureUserIsActive
    $meRes = $this->withHeaders($headers)->getJson('/api/v1/me');
    $meRes->assertOk();
    $meRes->assertJsonPath('user.status', 'suspended');

    // POST /api/logout is exempt and invalidates the current browser session
    $logoutRes = $this->withHeaders($headers)->postJson('/api/v1/logout');
    $logoutRes->assertOk();

    // Forget cached auth state in test application and verify request fails
    $this->app['auth']->forgetGuards();
    $afterLogoutRes = $this->withHeaders($headers)->getJson('/api/v1/me');
    $afterLogoutRes->assertUnauthorized();
});

test('password change by admin invalidates the old token', function (): void {
    $token = $this->customer->createToken('old-token')->plainTextToken;
    expect($this->customer->tokens()->count())->toBe(1);

    // Admin updates customer's password via UserService
    $dto = new UpdateUserDTO(
        name: $this->customer->name,
        email: $this->customer->email,
        role: 'customer',
        status: 'active',
        password: 'NewSecurePassword123!',
        quotaGroupId: null,
        hasQuotaGroupId: false,
        quota: null,
    );

    app(UserService::class)->update($this->customer, $dto, $this->admin);

    // Old tokens must be revoked
    expect($this->customer->tokens()->count())->toBe(0);

    // Request with old token must now be 401 Unauthenticated
    $res = $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json',
    ])->getJson('/api/v1/vms');

    $res->assertUnauthorized();
});

test('user status change or role change by admin revokes all tokens', function (): void {
    // 1. Status change to suspended revokes tokens
    $token1 = $this->customer->createToken('token-1')->plainTextToken;
    expect($this->customer->tokens()->count())->toBe(1);

    app(UserService::class)->updateStatus($this->admin, $this->customer, 'suspended');
    expect($this->customer->tokens()->count())->toBe(0);

    // 2. Role change revokes tokens
    $this->customer->update(['status' => 'active']);
    $token2 = $this->customer->createToken('token-2')->plainTextToken;
    expect($this->customer->tokens()->count())->toBe(1);

    $dto = new UpdateUserDTO(
        name: $this->customer->name,
        email: $this->customer->email,
        role: 'admin',
        status: 'active',
        password: null,
        quotaGroupId: null,
        hasQuotaGroupId: false,
        quota: null,
    );

    app(UserService::class)->update($this->customer, $dto, $this->admin);
    expect($this->customer->tokens()->count())->toBe(0);
});

test('demoting the last active admin returns 422', function (): void {
    // There is only one active admin ($this->admin)
    expect(User::role('admin')->where('status', 'active')->count())->toBe(1);

    // Create a second admin to test acting admin demoting another admin vs last admin
    $secondAdmin = User::factory()->create([
        'name' => 'Second Admin',
        'email' => 'second-admin@test.local',
        'status' => 'active',
    ]);
    $secondAdmin->assignRole('admin');
    expect(User::role('admin')->where('status', 'active')->count())->toBe(2);

    // 1. Demoting second admin when 2 active admins exist succeeds
    $demoteDto = new UpdateUserDTO(
        name: $secondAdmin->name,
        email: $secondAdmin->email,
        role: 'customer',
        status: 'active',
        password: null,
        quotaGroupId: null,
        hasQuotaGroupId: false,
        quota: null,
    );

    $updated = app(UserService::class)->update($secondAdmin, $demoteDto, $this->admin);
    expect($updated->hasRole('admin'))->toBeFalse();
    expect(User::role('admin')->where('status', 'active')->count())->toBe(1);

    // 2. Demoting the last remaining active admin via API returns 422
    $response = $this->actingAs($this->admin)->putJson("/api/v1/admin/users/{$this->admin->id}", [
        'name' => $this->admin->name,
        'email' => $this->admin->email,
        'role' => 'customer',
        'status' => 'active',
    ]);

    $response->assertStatus(422);
});

test('admin cannot change their own role', function (): void {
    // Even if another active admin exists, admin cannot change their own role
    $secondAdmin = User::factory()->create([
        'name' => 'Co-Admin',
        'email' => 'co-admin@test.local',
        'status' => 'active',
    ]);
    $secondAdmin->assignRole('admin');

    $response = $this->actingAs($this->admin)->putJson("/api/v1/admin/users/{$this->admin->id}", [
        'name' => $this->admin->name,
        'email' => $this->admin->email,
        'role' => 'customer',
        'status' => 'active',
    ]);

    $response->assertStatus(422);
    $response->assertJsonFragment([
        'message' => __('messages.admin.cannot_demote_self'),
    ]);
});

test('VmProvisioningService refuses non-active users', function (): void {
    $this->customer->update(['status' => 'suspended']);

    // VmProvisioningService::provision throws ValidationException
    expect(function (): void {
        app(VmProvisioningService::class)->provision($this->customer, [
            'name' => 'test-vm-refused',
            'instance_type' => 'virtual-machine',
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
        ]);
    })->toThrow(ValidationException::class);
});
