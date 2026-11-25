<?php

declare(strict_types=1);

use App\Models\Host;
use App\Models\VirtualMachine;
use App\Services\Hypervisor\Drivers\FakeHypervisorDriver;
use App\Services\Hypervisor\HypervisorManager;
use App\Services\Hypervisor\IncusEventSyncService;
use App\States\Vm\Deleted;
use App\States\Vm\Running;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

afterEach(function (): void {
    HypervisorManager::resetFake();
});

test('AC-5, FR-6 & FR-7: rollup aggregates raw samples before retention pruning deletes expired raw rows', function (): void {
    $vm = VirtualMachine::factory()->create([
        'status' => Running::class,
    ]);

    $now = 1795557600;
    $oldTs = $now - (13 * 3600);
    $recentTs = $now - 1800;

    DB::table('vm_metric_samples')->insert([
        [
            'virtual_machine_id' => $vm->id,
            'bucket_ts' => $oldTs,
            'avail_mask' => 31,
            'cpu_usage_bp' => 2500,
            'ram_used_bytes' => 1073741824,
            'disk_used_bytes' => 5368709120,
            'disk_read_bps' => 102400,
            'disk_write_bps' => 51200,
            'net_rx_bps' => 25600,
            'net_tx_bps' => 12800,
        ],
        [
            'virtual_machine_id' => $vm->id,
            'bucket_ts' => $recentTs,
            'avail_mask' => 31,
            'cpu_usage_bp' => 3500,
            'ram_used_bytes' => 1200000000,
            'disk_used_bytes' => 5400000000,
            'disk_read_bps' => 204800,
            'disk_write_bps' => 102400,
            'net_rx_bps' => 51200,
            'net_tx_bps' => 25600,
        ],
    ]);

    // Prior watermark covering $oldTs
    Cache::put('metrics:rollup:last_5m_ts', $oldTs, 3600);

    $this->artisan('metrics:rollup', ['--now' => $now])->assertSuccessful();
    $this->artisan('metrics:prune', ['--now' => $now])->assertSuccessful();

    expect(DB::table('vm_metric_samples')->where('virtual_machine_id', $vm->id)->where('bucket_ts', $oldTs)->count())->toBe(0)
        ->and(DB::table('vm_metric_samples')->where('virtual_machine_id', $vm->id)->where('bucket_ts', $recentTs)->count())->toBe(1)
        ->and(DB::table('vm_metric_rollups_5m')->where('virtual_machine_id', $vm->id)->where('bucket_ts', intdiv($oldTs, 300) * 300)->count())->toBe(1);
});

test('AC-7 & FR-11: disabled feature flag stops hypervisor collection while rollup and prune still run', function (): void {
    $fakeDriver = new FakeHypervisorDriver;
    HypervisorManager::fake($fakeDriver);

    config(['metrics.enabled' => false]);

    $host = Host::factory()->create([
        'driver' => 'fake',
        'is_active' => true,
        'status' => 'ok',
    ]);

    $vm = VirtualMachine::factory()->create([
        'host_id' => $host->id,
        'status' => Running::class,
    ]);

    $now = 1795557600;

    $this->artisan('metrics:collect', ['--sync' => true, '--now' => $now])->assertSuccessful();
    expect($fakeDriver->metricsRequestCount)->toBe(0);

    $closedRecentTs = $now - 600;
    $expiredTs = $now - (13 * 3600);

    DB::table('vm_metric_samples')->insert([
        [
            'virtual_machine_id' => $vm->id,
            'bucket_ts' => $expiredTs,
            'avail_mask' => 31,
            'cpu_usage_bp' => 1500,
            'ram_used_bytes' => 500000000,
            'disk_used_bytes' => 2000000000,
            'disk_read_bps' => 10000,
            'disk_write_bps' => 5000,
            'net_rx_bps' => 2000,
            'net_tx_bps' => 1000,
        ],
        [
            'virtual_machine_id' => $vm->id,
            'bucket_ts' => $closedRecentTs,
            'avail_mask' => 31,
            'cpu_usage_bp' => 2500,
            'ram_used_bytes' => 600000000,
            'disk_used_bytes' => 2100000000,
            'disk_read_bps' => 20000,
            'disk_write_bps' => 10000,
            'net_rx_bps' => 4000,
            'net_tx_bps' => 2000,
        ],
    ]);

    Cache::put('metrics:rollup:last_5m_ts', $expiredTs, 3600);

    $this->artisan('metrics:rollup', ['--now' => $now])->assertSuccessful();
    $this->artisan('metrics:prune', ['--now' => $now])->assertSuccessful();

    expect(DB::table('vm_metric_rollups_5m')->where('virtual_machine_id', $vm->id)->count())->toBe(2)
        ->and(DB::table('vm_metric_samples')->where('virtual_machine_id', $vm->id)->where('bucket_ts', $expiredTs)->count())->toBe(0)
        ->and(DB::table('vm_metric_samples')->where('virtual_machine_id', $vm->id)->where('bucket_ts', $closedRecentTs)->count())->toBe(1);
});

test('AC-9 & FR-12: transitioning VM to Deleted purges all metric tables and cache keys without touching other VMs', function (): void {
    $vm = VirtualMachine::factory()->create(['status' => Running::class]);
    $otherVm = VirtualMachine::factory()->create(['status' => Running::class]);

    $ts = 1795557600;

    foreach ([$vm->id, $otherVm->id] as $vmId) {
        DB::table('vm_metric_samples')->insert([
            'virtual_machine_id' => $vmId,
            'bucket_ts' => $ts,
            'avail_mask' => 31,
            'cpu_usage_bp' => 2000,
            'ram_used_bytes' => 1000000000,
            'disk_used_bytes' => 5000000000,
            'disk_read_bps' => 1000,
            'disk_write_bps' => 500,
            'net_rx_bps' => 200,
            'net_tx_bps' => 100,
        ]);

        DB::table('vm_metric_rollups_5m')->insert([
            'virtual_machine_id' => $vmId,
            'bucket_ts' => $ts,
            'sample_count' => 5,
            'avail_mask' => 31,
            'cpu_avg_bp' => 2000,
            'cpu_min_bp' => 1500,
            'cpu_max_bp' => 2500,
        ]);

        DB::table('vm_metric_rollups_1h')->insert([
            'virtual_machine_id' => $vmId,
            'bucket_ts' => $ts,
            'sample_count' => 60,
            'avail_mask' => 31,
            'cpu_avg_bp' => 2000,
            'cpu_min_bp' => 1000,
            'cpu_max_bp' => 3000,
        ]);

        DB::table('vm_metric_totals')->insert([
            'virtual_machine_id' => $vmId,
            'recorded_at_ts' => $ts,
            'cpu_cores' => 2,
            'ram_total_bytes' => 2147483648,
            'disk_total_bytes' => 21474836480,
        ]);
    }

    Cache::put("metrics:counters:{$vm->id}", ['ts' => 123], 600);
    Cache::put("metrics:totals_latest:{$vm->id}", ['cpu_cores' => 2], 600);
    Cache::put("metrics:counters:{$otherVm->id}", ['ts' => 123], 600);

    $vm->status->transitionTo(Deleted::class);
    $vm->save();

    foreach (['vm_metric_samples', 'vm_metric_rollups_5m', 'vm_metric_rollups_1h', 'vm_metric_totals'] as $table) {
        expect(DB::table($table)->where('virtual_machine_id', $vm->id)->count())->toBe(0)
            ->and(DB::table($table)->where('virtual_machine_id', $otherVm->id)->count())->toBe(1);
    }

    expect(Cache::get("metrics:counters:{$vm->id}"))->toBeNull()
        ->and(Cache::get("metrics:totals_latest:{$vm->id}"))->toBeNull()
        ->and(Cache::get("metrics:counters:{$otherVm->id}"))->toBe(['ts' => 123]);
});

test('Incus lifecycle instance-stopped event clears counter cache for the VM', function (): void {
    $host = Host::factory()->create([
        'driver' => 'fake',
        'is_active' => true,
        'status' => 'ok',
    ]);

    $vm = VirtualMachine::factory()->create([
        'host_id' => $host->id,
        'incus_project' => 'default',
        'name' => 'stop-event-vm',
        'status' => Running::class,
    ]);

    Cache::put("metrics:counters:{$vm->id}", ['ts' => 123], 600);

    app(IncusEventSyncService::class)->handleLifecycleEvent($host, [
        'type' => 'lifecycle',
        'metadata' => [
            'action' => 'instance-stopped',
            'source' => "/1.0/instances/{$vm->name}",
            'project' => $vm->incus_project,
        ],
    ]);

    expect(Cache::get("metrics:counters:{$vm->id}"))->toBeNull()
        ->and($vm->fresh()->status->getValue())->toBe('stopped');
});
