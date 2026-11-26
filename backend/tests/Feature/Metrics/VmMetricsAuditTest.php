<?php

declare(strict_types=1);

use App\Audit\AuditContext;
use App\Models\AuditLog;
use App\Models\Host;
use App\Models\User;
use App\Models\VirtualMachine;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Cache::flush();
    Role::firstOrCreate(['name' => 'admin']);
    Role::firstOrCreate(['name' => 'customer']);
    config([
        'metrics.enabled' => true,
        'metrics.interval_seconds' => 60,
        'metrics.audit_window_seconds' => 900,
        'metrics.rate_limit_per_minute' => 60,
    ]);
    $this->app->instance(AuditContext::class, new AuditContext);
});

it('deduplicates direct admin cross-tenant metric views within a 15-minute window (AC-10, FR-14)', function (): void {
    $host = Host::factory()->create();

    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');

    $tenant = User::factory()->create(['status' => 'active']);
    $tenant->assignRole('customer');

    $tenantVm = VirtualMachine::factory()->create([
        'user_id' => $tenant->id,
        'host_id' => $host->id,
    ]);

    Sanctum::actingAs($admin);

    // Simulate 4 auto-refresh polls spread over 10 minutes inside the 15-minute audit window.
    $this->getJson("/api/v1/vms/{$tenantVm->id}/metrics?range=1h")->assertOk();
    $this->travel(3)->minutes();
    $this->getJson("/api/v1/vms/{$tenantVm->id}/metrics?range=1h")->assertOk();
    $this->travel(3)->minutes();
    $this->getJson("/api/v1/vms/{$tenantVm->id}/metrics?range=1h")->assertOk();
    $this->travel(4)->minutes();
    $this->getJson("/api/v1/vms/{$tenantVm->id}/metrics?range=1h")->assertOk();

    expect(AuditLog::where('action', 'vm.metrics.viewed')->count())->toBe(1);

    /** @var AuditLog $log */
    $log = AuditLog::where('action', 'vm.metrics.viewed')->firstOrFail();

    expect($log->actor_id)->toBe($admin->id)
        ->and($log->tenant_user_id)->toBe($tenant->id)
        ->and($log->subject_id)->toBe((string) $tenantVm->id)
        ->and($log->visibility)->toBe('admin')
        ->and($log->context)->toBe([
            'range' => '1h',
            'window_seconds' => 900,
        ])
        ->and($log->context)->not->toHaveKeys(['series', 'cpu', 'memory', 'disk_space', 'disk_io', 'network', 'points', 'totals']);

    // Advance past the 15-minute window and poll twice more -> exactly one new audit row.
    $this->travel(16)->minutes();

    $this->getJson("/api/v1/vms/{$tenantVm->id}/metrics?range=1h")->assertOk();
    $this->getJson("/api/v1/vms/{$tenantVm->id}/metrics?range=1h")->assertOk();

    expect(AuditLog::where('action', 'vm.metrics.viewed')->count())->toBe(2);
});

it('records windowed cross-tenant audit entries when an admin views metrics while impersonating (FR-14)', function (): void {
    $host = Host::factory()->create();

    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');

    $tenant = User::factory()->create(['status' => 'active']);
    $tenant->assignRole('customer');

    $tenantVm = VirtualMachine::factory()->create([
        'user_id' => $tenant->id,
        'host_id' => $host->id,
    ]);

    $session = [
        'login_at' => now()->timestamp,
        'last_activity_at' => now()->timestamp,
        'impersonator_id' => $admin->id,
        'impersonation_expires_at' => now()->addMinutes(30)->timestamp,
    ];

    $this->actingAs($tenant, 'web')
        ->withSession($session)
        ->withHeader('Origin', 'http://localhost')
        ->getJson("/api/v1/vms/{$tenantVm->id}/metrics?range=1h")
        ->assertOk();

    $this->app['auth']->forgetGuards();
    $this->actingAs($tenant, 'web')
        ->withSession($session)
        ->withHeader('Origin', 'http://localhost')
        ->getJson("/api/v1/vms/{$tenantVm->id}/metrics?range=1h")
        ->assertOk();

    expect(AuditLog::where('action', 'vm.metrics.viewed')->count())->toBe(1);

    /** @var AuditLog $log */
    $log = AuditLog::where('action', 'vm.metrics.viewed')->firstOrFail();

    expect($log->impersonator_id)->toBe($admin->id)
        ->and($log->tenant_user_id)->toBe($tenant->id)
        ->and($log->visibility)->toBe('admin');
});

it('does not record cross-tenant audit rows for self-owned instance metric views', function (): void {
    $host = Host::factory()->create();

    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');

    $tenant = User::factory()->create(['status' => 'active']);
    $tenant->assignRole('customer');

    $tenantVm = VirtualMachine::factory()->create([
        'user_id' => $tenant->id,
        'host_id' => $host->id,
    ]);

    $adminOwnVm = VirtualMachine::factory()->create([
        'user_id' => $admin->id,
        'host_id' => $host->id,
    ]);

    Sanctum::actingAs($tenant);
    $this->getJson("/api/v1/vms/{$tenantVm->id}/metrics?range=1h")->assertOk();

    $this->app['auth']->forgetGuards();
    Sanctum::actingAs($admin);
    $this->getJson("/api/v1/vms/{$adminOwnVm->id}/metrics?range=1h")->assertOk();

    expect(AuditLog::where('action', 'vm.metrics.viewed')->count())->toBe(0);
});
