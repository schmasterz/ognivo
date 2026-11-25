<?php

declare(strict_types=1);

use App\DTO\Incus\RawInstanceMetricsDTO;
use App\Exceptions\FingerprintMismatchException;
use App\Models\Host;
use App\Models\VirtualMachine;
use App\Services\Hypervisor\Drivers\FakeHypervisorDriver;
use App\Services\Hypervisor\HypervisorManager;
use App\Services\Metrics\VmMetricsCollectorService;
use App\States\Vm\Running;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->fakeDriver = new FakeHypervisorDriver;
    HypervisorManager::fake($this->fakeDriver);

    config([
        'metrics.enabled' => true,
        'metrics.interval_seconds' => 60,
    ]);
});

afterEach(function (): void {
    HypervisorManager::resetFake();
});

test('AC-1: collects gauges on first cycle and positive rates on subsequent cycles', function (): void {
    $host = Host::factory()->create([
        'driver' => 'fake',
        'is_active' => true,
        'status' => 'ok',
    ]);

    $vm = VirtualMachine::factory()->create([
        'host_id' => $host->id,
        'status' => Running::class,
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 20,
    ]);

    $t0 = 1795560000;

    $this->artisan('metrics:collect', ['--sync' => true, '--now' => $t0])->assertSuccessful();

    $samplesAfterCycle1 = DB::table('vm_metric_samples')
        ->where('virtual_machine_id', $vm->id)
        ->orderBy('bucket_ts')
        ->get();

    expect($samplesAfterCycle1)->toHaveCount(1);
    $first = $samplesAfterCycle1->first();
    expect($first->ram_used_bytes)->not->toBeNull()
        ->and($first->disk_used_bytes)->not->toBeNull()
        ->and($first->cpu_usage_bp)->toBeNull()
        ->and($first->disk_read_bps)->toBeNull()
        ->and($first->disk_write_bps)->toBeNull()
        ->and($first->net_rx_bps)->toBeNull()
        ->and($first->net_tx_bps)->toBeNull();

    $this->artisan('metrics:collect', ['--sync' => true, '--now' => $t0 + 60])->assertSuccessful();
    $this->artisan('metrics:collect', ['--sync' => true, '--now' => $t0 + 120])->assertSuccessful();

    $samplesAfterCycle3 = DB::table('vm_metric_samples')
        ->where('virtual_machine_id', $vm->id)
        ->orderBy('bucket_ts')
        ->get();

    expect($samplesAfterCycle3)->toHaveCount(3);

    $allGaugesPresent = $samplesAfterCycle3->every(
        static fn (object $row): bool => $row->ram_used_bytes !== null && $row->disk_used_bytes !== null
    );
    expect($allGaugesPresent)->toBeTrue();

    $rowsWithAllPositiveRates = $samplesAfterCycle3->filter(
        static fn (object $row): bool => $row->cpu_usage_bp !== null && (int) $row->cpu_usage_bp > 0
            && $row->disk_read_bps !== null && (int) $row->disk_read_bps > 0
            && $row->disk_write_bps !== null && (int) $row->disk_write_bps > 0
            && $row->net_rx_bps !== null && (int) $row->net_rx_bps > 0
            && $row->net_tx_bps !== null && (int) $row->net_tx_bps > 0
    );

    expect($rowsWithAllPositiveRates->count())->toBeGreaterThanOrEqual(2);
});

test('AC-2: counter reset on VM reboot produces null rates instead of negative spikes and resumes on next cycle', function (): void {
    $host = Host::factory()->create([
        'driver' => 'fake',
        'is_active' => true,
        'status' => 'ok',
    ]);

    $vm = VirtualMachine::factory()->create([
        'host_id' => $host->id,
        'incus_project' => 'default',
        'name' => 'reboot-vm',
        'status' => Running::class,
        'cpu_cores' => 2,
    ]);

    $key = "default/{$vm->name}";
    $t0 = 1795560000;

    $makeDto = static fn (float $cpu, int $diskR, int $diskW, int $netRx, int $netTx): RawInstanceMetricsDTO => new RawInstanceMetricsDTO(
        project: 'default',
        name: $vm->name,
        instanceType: 'virtual-machine',
        cpuBusySecondsTotal: $cpu,
        effectiveCpus: 2,
        memoryTotalBytes: 2147483648,
        memoryAvailableBytes: 1073741824,
        filesystemTotalBytes: 21474836480,
        filesystemAvailBytes: 16106127360,
        diskReadBytesTotal: $diskR,
        diskWriteBytesTotal: $diskW,
        netRxBytesTotal: $netRx,
        netTxBytesTotal: $netTx,
        procsTotal: 42,
    );

    // Cycle 1 (seed)
    $this->fakeDriver->setHostMetrics($host, [$key => $makeDto(10.0, 100000, 50000, 20000, 10000)]);
    $this->artisan('metrics:collect', ['--sync' => true, '--now' => $t0])->assertSuccessful();

    // Cycle 2 (normal increase)
    $this->fakeDriver->setHostMetrics($host, [$key => $makeDto(22.0, 220000, 110000, 80000, 40000)]);
    $this->artisan('metrics:collect', ['--sync' => true, '--now' => $t0 + 60])->assertSuccessful();

    // Cycle 3 (counter reset after reboot)
    $this->fakeDriver->setHostMetrics($host, [$key => $makeDto(2.0, 10000, 5000, 2000, 1000)]);
    $this->artisan('metrics:collect', ['--sync' => true, '--now' => $t0 + 120])->assertSuccessful();

    // Cycle 4 (normal increase resumed)
    $this->fakeDriver->setHostMetrics($host, [$key => $makeDto(14.0, 130000, 65000, 62000, 31000)]);
    $this->artisan('metrics:collect', ['--sync' => true, '--now' => $t0 + 180])->assertSuccessful();

    $resetRow = DB::table('vm_metric_samples')
        ->where('virtual_machine_id', $vm->id)
        ->where('bucket_ts', $t0 + 120)
        ->first();

    expect($resetRow)->not->toBeNull()
        ->and($resetRow->cpu_usage_bp)->toBeNull()
        ->and($resetRow->disk_read_bps)->toBeNull()
        ->and($resetRow->disk_write_bps)->toBeNull()
        ->and($resetRow->net_rx_bps)->toBeNull()
        ->and($resetRow->net_tx_bps)->toBeNull()
        ->and($resetRow->ram_used_bytes)->not->toBeNull()
        ->and($resetRow->disk_used_bytes)->not->toBeNull();

    $resumedRow = DB::table('vm_metric_samples')
        ->where('virtual_machine_id', $vm->id)
        ->where('bucket_ts', $t0 + 180)
        ->first();

    expect($resumedRow)->not->toBeNull()
        ->and((int) $resumedRow->cpu_usage_bp)->toBeGreaterThan(0)
        ->and((int) $resumedRow->disk_read_bps)->toBeGreaterThan(0)
        ->and((int) $resumedRow->disk_write_bps)->toBeGreaterThan(0)
        ->and((int) $resumedRow->net_rx_bps)->toBeGreaterThan(0)
        ->and((int) $resumedRow->net_tx_bps)->toBeGreaterThan(0);
});

test('AC-3 & FR-13: isolates node failure, applies backoff, and recovers automatically after backoff', function (): void {
    $hostA = Host::factory()->create([
        'name' => 'host-a',
        'driver' => 'fake',
        'is_active' => true,
        'status' => 'ok',
    ]);
    $hostB = Host::factory()->create([
        'name' => 'host-b',
        'driver' => 'fake',
        'is_active' => true,
        'status' => 'ok',
    ]);

    $vmA = VirtualMachine::factory()->create([
        'host_id' => $hostA->id,
        'status' => Running::class,
    ]);
    $vmB = VirtualMachine::factory()->create([
        'host_id' => $hostB->id,
        'status' => Running::class,
    ]);

    $t0 = 1795560000;

    $this->fakeDriver->setFailAction("getHostInstanceMetrics:{$hostA->id}", true);

    $this->artisan('metrics:collect', ['--sync' => true, '--now' => $t0])->assertSuccessful();
    $this->artisan('metrics:collect', ['--sync' => true, '--now' => $t0 + 60])->assertSuccessful();

    expect(DB::table('vm_metric_samples')->where('virtual_machine_id', $vmA->id)->count())->toBe(0)
        ->and(DB::table('vm_metric_samples')->where('virtual_machine_id', $vmB->id)->count())->toBe(2);

    $hostA->refresh();
    expect($hostA->metrics_last_error)->not->toBeNull()
        ->and($hostA->metrics_consecutive_failures)->toBe(2)
        ->and($hostA->metrics_backoff_until)->not->toBeNull()
        ->and($hostA->metrics_backoff_until->greaterThan(Carbon::createFromTimestampUTC($t0 + 60)))->toBeTrue();

    $this->fakeDriver->setFailAction("getHostInstanceMetrics:{$hostA->id}", false);

    $this->artisan('metrics:collect', ['--sync' => true, '--now' => $t0 + 180])->assertSuccessful();

    $hostA->refresh();
    expect($hostA->metrics_consecutive_failures)->toBe(0)
        ->and($hostA->metrics_backoff_until)->toBeNull()
        ->and($hostA->metrics_last_success_at)->not->toBeNull()
        ->and(DB::table('vm_metric_samples')->where('virtual_machine_id', $vmA->id)->count())->toBe(1);
});

test('Section 10: certificate pin mismatch marks host and skips subsequent polls without retry storm', function (): void {
    $host = Host::factory()->create([
        'driver' => 'fake',
        'is_active' => true,
        'status' => 'ok',
    ]);

    VirtualMachine::factory()->create([
        'host_id' => $host->id,
        'status' => Running::class,
    ]);

    $t0 = 1795560000;

    $this->fakeDriver->setActionCallback(
        'getHostInstanceMetrics',
        static fn () => throw new FingerprintMismatchException('', '', 'Server certificate public key does not match pinned public key.', 90)
    );

    $this->artisan('metrics:collect', ['--sync' => true, '--now' => $t0])->assertSuccessful();

    $freshHost = $host->fresh();
    expect($freshHost)->not->toBeNull()
        ->and($freshHost->status)->toBe('fingerprint_mismatch')
        ->and($freshHost->metrics_last_error)->not->toBeNull();

    $calls = 0;
    $this->fakeDriver->setActionCallback('getHostInstanceMetrics', function () use (&$calls): array {
        $calls++;

        return [];
    });

    $this->artisan('metrics:collect', ['--sync' => true, '--now' => $t0 + 60])->assertSuccessful();

    expect($calls)->toBe(0);
});

test('AC-8 & D3: instance rename preserves metrics history under the same virtual_machine_id', function (): void {
    $host = Host::factory()->create([
        'driver' => 'fake',
        'is_active' => true,
        'status' => 'ok',
    ]);

    $vm = VirtualMachine::factory()->create([
        'host_id' => $host->id,
        'name' => 'original-instance',
        'status' => Running::class,
    ]);

    $t0 = 1795560000;

    $this->artisan('metrics:collect', ['--sync' => true, '--now' => $t0])->assertSuccessful();
    $this->artisan('metrics:collect', ['--sync' => true, '--now' => $t0 + 60])->assertSuccessful();

    $vm->update(['name' => 'renamed-instance']);

    $this->artisan('metrics:collect', ['--sync' => true, '--now' => $t0 + 120])->assertSuccessful();

    $samples = DB::table('vm_metric_samples')
        ->where('virtual_machine_id', $vm->id)
        ->orderBy('bucket_ts')
        ->get();

    expect($samples)->toHaveCount(3)
        ->and($samples->pluck('virtual_machine_id')->unique()->values()->all())->toBe([$vm->id]);
});

test('AC-12 & FR-4: ignores unmanaged instances in same and unknown projects', function (): void {
    $host = Host::factory()->create([
        'driver' => 'fake',
        'is_active' => true,
        'status' => 'ok',
    ]);

    $vm = VirtualMachine::factory()->create([
        'host_id' => $host->id,
        'incus_project' => 'default',
        'name' => 'managed-vm',
        'status' => Running::class,
    ]);

    $payload = <<<'METRICS'
# HELP incus_cpu_seconds_total The total number of CPU time used in seconds.
# TYPE incus_cpu_seconds_total counter
incus_cpu_seconds_total{cpu="0",mode="user",name="managed-vm",project="default",type="virtual-machine"} 10.5
incus_cpu_seconds_total{cpu="0",mode="user",name="unmanaged-1",project="default",type="virtual-machine"} 20.5
incus_cpu_seconds_total{cpu="0",mode="user",name="unmanaged-2",project="unknown-proj",type="container"} 30.5
# HELP incus_memory_MemTotal_bytes The total amount of memory.
# TYPE incus_memory_MemTotal_bytes gauge
incus_memory_MemTotal_bytes{name="managed-vm",project="default",type="virtual-machine"} 2147483648
incus_memory_MemTotal_bytes{name="unmanaged-1",project="default",type="virtual-machine"} 2147483648
incus_memory_MemTotal_bytes{name="unmanaged-2",project="unknown-proj",type="container"} 1073741824
# HELP incus_memory_MemAvailable_bytes The amount of available memory.
# TYPE incus_memory_MemAvailable_bytes gauge
incus_memory_MemAvailable_bytes{name="managed-vm",project="default",type="virtual-machine"} 1073741824
incus_memory_MemAvailable_bytes{name="unmanaged-1",project="default",type="virtual-machine"} 1073741824
incus_memory_MemAvailable_bytes{name="unmanaged-2",project="unknown-proj",type="container"} 536870912
# EOF
METRICS;

    $this->fakeDriver->setHostMetrics($host, $payload);

    $t0 = 1795560000;
    $this->artisan('metrics:collect', ['--sync' => true, '--now' => $t0])->assertSuccessful();

    $sampleVmIds = DB::table('vm_metric_samples')->pluck('virtual_machine_id')->all();
    $totalVmIds = DB::table('vm_metric_totals')->pluck('virtual_machine_id')->all();

    expect($sampleVmIds)->toBe([$vm->id])
        ->and($totalVmIds)->toBe([$vm->id]);
});

test('AC-13 & FR-5: VM without guest agent stores null RAM and disk space with avail_mask 25 and stores rates', function (): void {
    $host = Host::factory()->create([
        'driver' => 'fake',
        'is_active' => true,
        'status' => 'ok',
    ]);

    $vm = VirtualMachine::factory()->create([
        'host_id' => $host->id,
        'name' => 'vm-noagent-1',
        'incus_project' => 'proj-00000000-0000-0000-0000-000000000001',
        'instance_type' => 'virtual-machine',
        'status' => Running::class,
        'cpu_cores' => 2,
    ]);

    $fixture = file_get_contents(base_path('tests/Fixtures/Incus/incus-6.0-metrics-vm-no-agent.txt'));
    expect($fixture)->toBeString();

    $t0 = 1795560000;

    $this->fakeDriver->setHostMetrics($host, (string) $fixture);
    $this->artisan('metrics:collect', ['--sync' => true, '--now' => $t0])->assertSuccessful();

    $cycle2Fixture = str_replace(
        [
            'incus_cpu_seconds_total{cpu="0",mode="system",name="vm-noagent-1",project="proj-00000000-0000-0000-0000-000000000001",type="virtual-machine"} 5.12',
            'incus_cpu_seconds_total{cpu="0",mode="user",name="vm-noagent-1",project="proj-00000000-0000-0000-0000-000000000001",type="virtual-machine"} 3.48',
            'incus_disk_read_bytes_total{device="dev_qemu_sda-root",name="vm-noagent-1",project="proj-00000000-0000-0000-0000-000000000001",type="virtual-machine"} 5.24288e+07',
            'incus_disk_written_bytes_total{device="dev_qemu_sda-root",name="vm-noagent-1",project="proj-00000000-0000-0000-0000-000000000001",type="virtual-machine"} 1.6777216e+07',
            'incus_network_receive_bytes_total{device="eth0",name="vm-noagent-1",project="proj-00000000-0000-0000-0000-000000000001",type="virtual-machine"} 4096',
            'incus_network_transmit_bytes_total{device="eth0",name="vm-noagent-1",project="proj-00000000-0000-0000-0000-000000000001",type="virtual-machine"} 2048',
        ],
        [
            'incus_cpu_seconds_total{cpu="0",mode="system",name="vm-noagent-1",project="proj-00000000-0000-0000-0000-000000000001",type="virtual-machine"} 10.12',
            'incus_cpu_seconds_total{cpu="0",mode="user",name="vm-noagent-1",project="proj-00000000-0000-0000-0000-000000000001",type="virtual-machine"} 8.48',
            'incus_disk_read_bytes_total{device="dev_qemu_sda-root",name="vm-noagent-1",project="proj-00000000-0000-0000-0000-000000000001",type="virtual-machine"} 6.24288e+07',
            'incus_disk_written_bytes_total{device="dev_qemu_sda-root",name="vm-noagent-1",project="proj-00000000-0000-0000-0000-000000000001",type="virtual-machine"} 2.6777216e+07',
            'incus_network_receive_bytes_total{device="eth0",name="vm-noagent-1",project="proj-00000000-0000-0000-0000-000000000001",type="virtual-machine"} 64096',
            'incus_network_transmit_bytes_total{device="eth0",name="vm-noagent-1",project="proj-00000000-0000-0000-0000-000000000001",type="virtual-machine"} 32048',
        ],
        (string) $fixture,
    );

    $this->fakeDriver->setHostMetrics($host, $cycle2Fixture);
    $this->artisan('metrics:collect', ['--sync' => true, '--now' => $t0 + 60])->assertSuccessful();

    $row = DB::table('vm_metric_samples')
        ->where('virtual_machine_id', $vm->id)
        ->where('bucket_ts', $t0 + 60)
        ->first();

    expect($row)->not->toBeNull()
        ->and($row->ram_used_bytes)->toBeNull()
        ->and($row->disk_used_bytes)->toBeNull()
        ->and((int) $row->avail_mask)->toBe(25)
        ->and($row->cpu_usage_bp)->not->toBeNull()
        ->and($row->disk_read_bps)->not->toBeNull()
        ->and($row->disk_write_bps)->not->toBeNull()
        ->and($row->net_rx_bps)->not->toBeNull()
        ->and($row->net_tx_bps)->not->toBeNull();
});

test('AC-15 & NFR-2: concurrent collector runs for same timestamp are idempotent without duplicating rows', function (): void {
    $host = Host::factory()->create([
        'driver' => 'fake',
        'is_active' => true,
        'status' => 'ok',
    ]);

    $vm = VirtualMachine::factory()->create([
        'host_id' => $host->id,
        'incus_project' => 'default',
        'name' => 'idempotent-vm',
        'status' => Running::class,
        'cpu_cores' => 2,
    ]);

    $key = "default/{$vm->name}";
    $t0 = 1795560000;

    $dtoT0 = new RawInstanceMetricsDTO(
        project: 'default',
        name: $vm->name,
        instanceType: 'virtual-machine',
        cpuBusySecondsTotal: 10.0,
        effectiveCpus: 2,
        memoryTotalBytes: 2147483648,
        memoryAvailableBytes: 1073741824,
        filesystemTotalBytes: 21474836480,
        filesystemAvailBytes: 16106127360,
        diskReadBytesTotal: 100000,
        diskWriteBytesTotal: 50000,
        netRxBytesTotal: 20000,
        netTxBytesTotal: 10000,
        procsTotal: 40,
    );

    $dtoT1 = new RawInstanceMetricsDTO(
        project: 'default',
        name: $vm->name,
        instanceType: 'virtual-machine',
        cpuBusySecondsTotal: 22.0,
        effectiveCpus: 2,
        memoryTotalBytes: 2147483648,
        memoryAvailableBytes: 1000000000,
        filesystemTotalBytes: 21474836480,
        filesystemAvailBytes: 16000000000,
        diskReadBytesTotal: 220000,
        diskWriteBytesTotal: 110000,
        netRxBytesTotal: 80000,
        netTxBytesTotal: 40000,
        procsTotal: 42,
    );

    $collector = app(VmMetricsCollectorService::class);

    $this->fakeDriver->setHostMetrics($host, [$key => $dtoT0]);
    $collector->collectForHost($host, $t0);

    $this->fakeDriver->setHostMetrics($host, [$key => $dtoT1]);
    $collector->collectForHost($host, $t0 + 60);

    $firstRunRow = DB::table('vm_metric_samples')
        ->where('virtual_machine_id', $vm->id)
        ->where('bucket_ts', $t0 + 60)
        ->first();

    // Second run at exact same timestamp $t0 + 60
    $collector->collectForHost($host, $t0 + 60);

    $secondRunRow = DB::table('vm_metric_samples')
        ->where('virtual_machine_id', $vm->id)
        ->where('bucket_ts', $t0 + 60)
        ->first();

    expect(DB::table('vm_metric_samples')->where('virtual_machine_id', $vm->id)->count())->toBe(2)
        ->and($secondRunRow)->not->toBeNull()
        ->and($firstRunRow)->not->toBeNull()
        ->and($secondRunRow->cpu_usage_bp)->toBe($firstRunRow->cpu_usage_bp)
        ->and($secondRunRow->disk_read_bps)->toBe($firstRunRow->disk_read_bps)
        ->and($secondRunRow->disk_write_bps)->toBe($firstRunRow->disk_write_bps)
        ->and($secondRunRow->net_rx_bps)->toBe($firstRunRow->net_rx_bps)
        ->and($secondRunRow->net_tx_bps)->toBe($firstRunRow->net_tx_bps);
});

test('AC-19 & NFR-9: performs one bulk write statement per host node instead of one per VM', function (): void {
    $hosts = Host::factory()->count(3)->create([
        'driver' => 'fake',
        'is_active' => true,
        'status' => 'ok',
    ]);

    foreach ($hosts as $host) {
        VirtualMachine::factory()->count(5)->create([
            'host_id' => $host->id,
            'status' => Running::class,
        ]);
    }

    $t0 = 1795560000;

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->artisan('metrics:collect', ['--sync' => true, '--now' => $t0])->assertSuccessful();

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    $bulkSampleWrites = array_values(array_filter(
        $queries,
        static function (array $entry): bool {
            $sql = strtolower((string) ($entry['query'] ?? ''));

            return str_contains($sql, 'vm_metric_samples')
                && (str_contains($sql, 'insert into') || str_contains($sql, 'upsert'));
        }
    ));

    expect(DB::table('vm_metric_samples')->count())->toBe(15)
        ->and(count($bulkSampleWrites))->toBe(3);
});
