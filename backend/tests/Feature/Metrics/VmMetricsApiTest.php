<?php

declare(strict_types=1);

use App\Models\Host;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\Metrics\VmMetricsCollectorService;
use App\States\Vm\Deleted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Cache::flush();
    Role::firstOrCreate(['name' => 'admin']);
    Role::firstOrCreate(['name' => 'customer']);
    config([
        'metrics.enabled' => true,
        'metrics.interval_seconds' => 60,
        'metrics.retention.raw_hours' => 12,
        'metrics.retention.rollup_5m_days' => 8,
        'metrics.retention.rollup_1h_days' => 35,
        'metrics.rate_limit_per_minute' => 60,
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('isolates cached and uncached metrics behind 404 for other tenants (AC-4, FR-10, NFR-6)', function (): void {
    $host = Host::factory()->create();

    $tenantA = User::factory()->create(['status' => 'active']);
    $tenantA->assignRole('customer');

    $tenantB = User::factory()->create(['status' => 'active']);
    $tenantB->assignRole('customer');

    $vmB = VirtualMachine::factory()->create([
        'user_id' => $tenantB->id,
        'host_id' => $host->id,
    ]);

    // Populate the shared response cache entry as Tenant B.
    Sanctum::actingAs($tenantB);
    $this->getJson("/api/vms/{$vmB->id}/metrics?range=1h")->assertOk();

    // Switch to Tenant A and verify indistinguishable 404 responses for Tenant B's cached VM and a nonexistent VM.
    config(['app.debug' => false]);
    $this->app['auth']->forgetGuards();
    Sanctum::actingAs($tenantA);

    $otherResponse = $this->getJson("/api/vms/{$vmB->id}/metrics?range=1h");
    $missingResponse = $this->getJson('/api/vms/999999/metrics?range=1h');

    $otherResponse->assertNotFound();
    $missingResponse->assertNotFound();

    expect(array_keys($otherResponse->json()))->toBe(['message'])
        ->and(array_keys($missingResponse->json()))->toBe(['message'])
        ->and(array_keys($otherResponse->json()))->toBe(array_keys($missingResponse->json()));

    // Invalid query parameters on another tenant's VM must still fail authorization/existence with 404, not 422.
    $this->getJson("/api/vms/{$vmB->id}/metrics?range=999d")->assertNotFound();
});

it('selects the expected storage tier and caps points at 500 across all five ranges (AC-6, FR-8)', function (): void {
    $host = Host::factory()->create();
    $owner = User::factory()->create(['status' => 'active']);
    $owner->assignRole('customer');

    $vm = VirtualMachine::factory()->create([
        'user_id' => $owner->id,
        'host_id' => $host->id,
    ]);

    Sanctum::actingAs($owner);

    $expectedTiers = [
        '1h' => 'raw',
        '6h' => 'raw',
        '24h' => 'rollup_5m',
        '7d' => 'rollup_5m',
        '30d' => 'rollup_1h',
    ];

    foreach ($expectedTiers as $range => $expectedTier) {
        $response = $this->getJson("/api/vms/{$vm->id}/metrics?range={$range}")->assertOk();

        expect($response->json('tier'))->toBe($expectedTier)
            ->and(count($response->json('series.cpu.points')))->toBeLessThanOrEqual(500);

        if ($range === '7d') {
            expect($response->json('bucket_width_seconds'))->toBe(1500)
                ->and(count($response->json('series.cpu.points')))->toBe(403);
        }

        if ($range === '30d') {
            expect($response->json('bucket_width_seconds'))->toBe(7200)
                ->and(count($response->json('series.cpu.points')))->toBe(360);
        }
    }
});

it('returns 503 when metrics are disabled without discarding previously collected samples (AC-7, FR-11)', function (): void {
    $host = Host::factory()->create();
    $owner = User::factory()->create(['status' => 'active']);
    $owner->assignRole('customer');

    $vm = VirtualMachine::factory()->create([
        'user_id' => $owner->id,
        'host_id' => $host->id,
    ]);

    $sampleTs = intdiv(time(), 60) * 60 - 60;
    DB::table('vm_metric_samples')->insert([
        'virtual_machine_id' => $vm->id,
        'bucket_ts' => $sampleTs,
        'avail_mask' => VmMetricsCollectorService::MASK_ALL,
        'cpu_usage_bp' => 3250,
        'ram_used_bytes' => 536_870_912,
        'disk_used_bytes' => 2_147_483_648,
        'disk_read_bps' => 1024,
        'disk_write_bps' => 2048,
        'net_rx_bps' => 4096,
        'net_tx_bps' => 8192,
    ]);

    config(['metrics.enabled' => false]);
    Sanctum::actingAs($owner);

    $this->getJson("/api/vms/{$vm->id}/metrics")
        ->assertStatus(503)
        ->assertJsonPath('code', 'metrics_disabled');

    $this->getJson('/api/settings/public')
        ->assertOk()
        ->assertJsonPath('metrics_enabled', false);

    config(['metrics.enabled' => true]);

    $response = $this->getJson("/api/vms/{$vm->id}/metrics")->assertOk();
    $matchingPoint = collect($response->json('series.cpu.points'))->firstWhere('ts', $sampleTs);

    expect($matchingPoint)->not->toBeNull()
        ->and((float) $matchingPoint['avg'])->toBe(32.5)
        ->and($matchingPoint['reason'])->toBeNull();
});

it('returns 404 for deleted instances even for owner or admin (AC-9, FR-12)', function (): void {
    $host = Host::factory()->create();

    $owner = User::factory()->create(['status' => 'active']);
    $owner->assignRole('customer');

    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');

    $vm = VirtualMachine::factory()->create([
        'user_id' => $owner->id,
        'host_id' => $host->id,
        'status' => Deleted::class,
    ]);

    Sanctum::actingAs($owner);
    $this->getJson("/api/vms/{$vm->id}/metrics")->assertNotFound();

    $this->app['auth']->forgetGuards();
    Sanctum::actingAs($admin);
    $this->getJson("/api/vms/{$vm->id}/metrics")->assertNotFound();
});

it('fills the tail seam of 24h rollup queries from raw samples (AC-14, FR-8)', function (): void {
    $host = Host::factory()->create();
    $owner = User::factory()->create(['status' => 'active']);
    $owner->assignRole('customer');

    $vm = VirtualMachine::factory()->create([
        'user_id' => $owner->id,
        'host_id' => $host->id,
    ]);

    $current5mBucket = intdiv(time(), 300) * 300;

    DB::table('vm_metric_rollups_5m')->insert([
        'virtual_machine_id' => $vm->id,
        'bucket_ts' => $current5mBucket - 300,
        'sample_count' => 5,
        'avail_mask' => VmMetricsCollectorService::MASK_ALL,
        'cpu_avg_bp' => 2000,
        'cpu_min_bp' => 2000,
        'cpu_max_bp' => 2000,
    ]);

    DB::table('vm_metric_samples')->insert([
        'virtual_machine_id' => $vm->id,
        'bucket_ts' => $current5mBucket,
        'avail_mask' => VmMetricsCollectorService::MASK_ALL,
        'cpu_usage_bp' => 4500,
    ]);

    Sanctum::actingAs($owner);
    $response = $this->getJson("/api/vms/{$vm->id}/metrics?range=24h")->assertOk();

    $points = $response->json('series.cpu.points');
    $lastPoint = $points[count($points) - 1];
    $secondToLastPoint = $points[count($points) - 2];

    expect($lastPoint['ts'])->toBe($current5mBucket)
        ->and((float) $lastPoint['avg'])->toBe(45.0)
        ->and($lastPoint['reason'])->toBeNull()
        ->and($secondToLastPoint['ts'])->toBe($current5mBucket - 300)
        ->and((float) $secondToLastPoint['avg'])->toBe(20.0);
});

it('distinguishes gap nulls from unavailable guest-agent nulls (AC-13, AC-16, FR-5, FR-8)', function (): void {
    $host = Host::factory()->create();
    $owner = User::factory()->create(['status' => 'active']);
    $owner->assignRole('customer');

    $vm = VirtualMachine::factory()->create([
        'user_id' => $owner->id,
        'host_id' => $host->id,
    ]);

    $t1 = intdiv(time(), 60) * 60 - 120;

    DB::table('vm_metric_samples')->insert([
        'virtual_machine_id' => $vm->id,
        'bucket_ts' => $t1,
        'avail_mask' => VmMetricsCollectorService::BIT_CPU | VmMetricsCollectorService::BIT_DISK_IO | VmMetricsCollectorService::BIT_NETWORK,
        'cpu_usage_bp' => 1500,
        'ram_used_bytes' => null,
        'disk_used_bytes' => null,
        'disk_read_bps' => 1024,
        'disk_write_bps' => 2048,
        'net_rx_bps' => 4096,
        'net_tx_bps' => 8192,
    ]);

    Sanctum::actingAs($owner);
    $response = $this->getJson("/api/vms/{$vm->id}/metrics?range=1h")->assertOk();

    $cpuPoints = collect($response->json('series.cpu.points'));
    $memoryPoints = collect($response->json('series.memory.points'));
    $diskSpacePoints = collect($response->json('series.disk_space.points'));

    $cpuAtT1 = $cpuPoints->firstWhere('ts', $t1);
    $memoryAtT1 = $memoryPoints->firstWhere('ts', $t1);
    $diskSpaceAtT1 = $diskSpacePoints->firstWhere('ts', $t1);
    $gapPoint = $cpuPoints->first(static fn (array $pt): bool => $pt['ts'] !== $t1);

    expect($gapPoint)->not->toBeNull()
        ->and($gapPoint['avg'])->toBeNull()
        ->and($gapPoint['reason'])->toBe('gap')
        ->and($cpuAtT1)->not->toBeNull()
        ->and((float) $cpuAtT1['avg'])->toBe(15.0)
        ->and($cpuAtT1['reason'])->toBeNull()
        ->and($memoryAtT1)->not->toBeNull()
        ->and($memoryAtT1['avg'])->toBeNull()
        ->and($memoryAtT1['reason'])->toBe('unavailable')
        ->and($diskSpaceAtT1)->not->toBeNull()
        ->and($diskSpaceAtT1['avg'])->toBeNull()
        ->and($diskSpaceAtT1['reason'])->toBe('unavailable')
        ->and($response->json('series.memory.available'))->toBeFalse()
        ->and($response->json('series.memory.unavailable_reason'))->toBe('guest_agent_not_running');
});

it('validates query parameters and enforces per-user rate limiting (AC-17, FR-8, NFR-6)', function (): void {
    $host = Host::factory()->create();

    $owner = User::factory()->create(['status' => 'active']);
    $owner->assignRole('customer');

    $vm = VirtualMachine::factory()->create([
        'user_id' => $owner->id,
        'host_id' => $host->id,
    ]);

    Sanctum::actingAs($owner);

    $this->getJson("/api/vms/{$vm->id}/metrics?metrics=gpu")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['metrics.0']);

    $this->getJson("/api/vms/{$vm->id}/metrics?range=90d")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['range']);

    $this->getJson("/api/vms/{$vm->id}/metrics?max_points=501")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['max_points']);

    // Use a dedicated user so the validation requests above do not consume the 3-request rate limit budget.
    $rateLimitedUser = User::factory()->create(['status' => 'active']);
    $rateLimitedUser->assignRole('customer');

    $rateLimitedVm = VirtualMachine::factory()->create([
        'user_id' => $rateLimitedUser->id,
        'host_id' => $host->id,
    ]);

    config(['metrics.rate_limit_per_minute' => 3]);

    $this->app['auth']->forgetGuards();
    Sanctum::actingAs($rateLimitedUser);

    for ($i = 0; $i < 3; $i++) {
        $this->getJson("/api/vms/{$rateLimitedVm->id}/metrics?range=1h")->assertOk();
    }

    $this->getJson("/api/vms/{$rateLimitedVm->id}/metrics?range=1h")->assertStatus(429);
});

it('serves concurrent requests from shared cache and supports ETag 304 Not Modified (AC-18, FR-8, NFR-9)', function (): void {
    config(['metrics.rate_limit_per_minute' => 200]);

    $host = Host::factory()->create();
    $owner = User::factory()->create(['status' => 'active']);
    $owner->assignRole('customer');

    $vm = VirtualMachine::factory()->create([
        'user_id' => $owner->id,
        'host_id' => $host->id,
    ]);

    $sampleTs = intdiv(time(), 60) * 60 - 60;
    DB::table('vm_metric_samples')->insert([
        'virtual_machine_id' => $vm->id,
        'bucket_ts' => $sampleTs,
        'avail_mask' => VmMetricsCollectorService::MASK_ALL,
        'cpu_usage_bp' => 2500,
    ]);

    Sanctum::actingAs($owner);

    $countSampleSelects = static function (array $queries): int {
        return count(array_filter($queries, static function (array $q): bool {
            $sql = strtolower($q['query']);

            return str_contains($sql, 'select') && str_contains($sql, 'vm_metric_samples');
        }));
    };

    DB::flushQueryLog();
    DB::enableQueryLog();

    $firstResponse = $this->getJson("/api/vms/{$vm->id}/metrics?range=1h")->assertOk();
    $firstRequestSampleQueries = $countSampleSelects(DB::getQueryLog());

    DB::flushQueryLog();
    for ($i = 0; $i < 19; $i++) {
        $this->getJson("/api/vms/{$vm->id}/metrics?range=1h")->assertOk();
    }
    $cachedRequestsSampleQueries = $countSampleSelects(DB::getQueryLog());
    DB::disableQueryLog();

    expect($firstRequestSampleQueries)->toBeGreaterThanOrEqual(1)
        ->and($cachedRequestsSampleQueries)->toBe(0);

    $etag = $firstResponse->headers->get('ETag');
    expect($etag)->not->toBeEmpty();

    $notModifiedResponse = $this->withHeader('If-None-Match', (string) $etag)
        ->getJson("/api/vms/{$vm->id}/metrics?range=1h");

    $notModifiedResponse->assertStatus(304);
    expect($notModifiedResponse->getContent())->toBe('');
});

it('enforces minimum retention in admin settings and hides unavailable ranges from UI and API (AC-20, FR-7, FR-9)', function (): void {
    $host = Host::factory()->create();

    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');

    $owner = User::factory()->create(['status' => 'active']);
    $owner->assignRole('customer');

    $vm = VirtualMachine::factory()->create([
        'user_id' => $owner->id,
        'host_id' => $host->id,
    ]);

    Sanctum::actingAs($admin);
    $this->putJson('/api/admin/settings', [
        'metrics_retention_1h_days' => 20,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['metrics_retention_1h_days']);

    config(['metrics.retention.rollup_1h_days' => 20]);

    $this->app['auth']->forgetGuards();
    Sanctum::actingAs($owner);

    $this->getJson('/api/settings/public')
        ->assertOk()
        ->assertJsonPath('metrics_available_ranges', ['1h', '6h', '24h', '7d']);

    $this->getJson("/api/vms/{$vm->id}/metrics?range=30d")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['range']);
});
