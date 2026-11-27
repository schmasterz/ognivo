<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Hypervisor;

use App\DTO\Hypervisor\RawInstanceMetricsDTO;
use App\Services\Hypervisor\OpenMetricsParser;
use Tests\TestCase;

final class OpenMetricsParserTest extends TestCase
{
    private OpenMetricsParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new OpenMetricsParser;
    }

    public function test_parses_incus_6_0_contract_fixture_with_all_instances_and_exact_values(): void
    {
        $payload = file_get_contents(base_path('tests/Fixtures/Incus/incus-6.0-metrics.txt'));
        $this->assertIsString($payload);

        $parsed = $this->parser->parse($payload);

        $this->assertCount(3, $parsed);
        $this->assertArrayHasKey('proj-00000000-0000-0000-0000-000000000001/ct-rocky-1', $parsed);
        $this->assertArrayHasKey('proj-00000000-0000-0000-0000-000000000001/vm-arch-1', $parsed);
        $this->assertArrayHasKey('proj-00000000-0000-0000-0000-000000000001/vm-debian-1', $parsed);

        $ctRocky = $parsed['proj-00000000-0000-0000-0000-000000000001/ct-rocky-1'];
        $this->assertInstanceOf(RawInstanceMetricsDTO::class, $ctRocky);
        $this->assertSame('proj-00000000-0000-0000-0000-000000000001', $ctRocky->project);
        $this->assertSame('ct-rocky-1', $ctRocky->name);
        $this->assertSame('container', $ctRocky->instanceType);
        $this->assertEqualsWithDelta(3.750298, $ctRocky->cpuBusySecondsTotal, 0.0001);
        $this->assertSame(1, $ctRocky->effectiveCpus);
        $this->assertSame(2048000000, $ctRocky->memoryTotalBytes);
        $this->assertSame(2019078144, $ctRocky->memoryAvailableBytes);
        $this->assertSame(501809635328, $ctRocky->filesystemTotalBytes);
        $this->assertSame(267550326784, $ctRocky->filesystemAvailBytes);
        $this->assertSame(90730496, $ctRocky->diskReadBytesTotal);
        $this->assertSame(1429504, $ctRocky->diskWriteBytesTotal);
        $this->assertSame(7762, $ctRocky->netRxBytesTotal);
        $this->assertSame(9494, $ctRocky->netTxBytesTotal);
        $this->assertSame(15, $ctRocky->procsTotal);

        $vmArch = $parsed['proj-00000000-0000-0000-0000-000000000001/vm-arch-1'];
        $this->assertSame('virtual-machine', $vmArch->instanceType);
        $this->assertEqualsWithDelta(39.42, $vmArch->cpuBusySecondsTotal, 0.0001);
        $this->assertSame(1, $vmArch->effectiveCpus);
        $this->assertSame(1947873280, $vmArch->memoryTotalBytes);
        $this->assertSame(1637617664, $vmArch->memoryAvailableBytes);
        $this->assertSame(19203981312, $vmArch->filesystemTotalBytes);
        $this->assertSame(17922736128, $vmArch->filesystemAvailBytes);
        $this->assertSame(150820352, $vmArch->diskReadBytesTotal);
        $this->assertSame(832619008, $vmArch->diskWriteBytesTotal);
        $this->assertSame(15262, $vmArch->netRxBytesTotal);
        $this->assertSame(489382, $vmArch->netTxBytesTotal);
        $this->assertSame(24, $vmArch->procsTotal);

        $vmDebian = $parsed['proj-00000000-0000-0000-0000-000000000001/vm-debian-1'];
        $this->assertSame('virtual-machine', $vmDebian->instanceType);
        $this->assertEqualsWithDelta(12.31, $vmDebian->cpuBusySecondsTotal, 0.0001);
        $this->assertSame(1, $vmDebian->effectiveCpus);
        $this->assertSame(1941680128, $vmDebian->memoryTotalBytes);
        $this->assertSame(1667633152, $vmDebian->memoryAvailableBytes);
        $this->assertSame(19204476928, $vmDebian->filesystemTotalBytes);
        $this->assertSame(18040303616, $vmDebian->filesystemAvailBytes);
        $this->assertSame(104053760, $vmDebian->diskReadBytesTotal);
        $this->assertSame(496202752, $vmDebian->diskWriteBytesTotal);
        $this->assertSame(2919, $vmDebian->netRxBytesTotal);
        $this->assertSame(2068, $vmDebian->netTxBytesTotal);
        $this->assertSame(10, $vmDebian->procsTotal);
    }

    public function test_parses_vm_without_guest_agent_leaving_memory_and_filesystem_null(): void
    {
        $payload = file_get_contents(base_path('tests/Fixtures/Incus/incus-6.0-metrics-vm-no-agent.txt'));
        $this->assertIsString($payload);

        $parsed = $this->parser->parse($payload);

        $this->assertCount(2, $parsed);
        $this->assertArrayHasKey('proj-00000000-0000-0000-0000-000000000001/vm-noagent-1', $parsed);

        $vm = $parsed['proj-00000000-0000-0000-0000-000000000001/vm-noagent-1'];
        $this->assertSame('virtual-machine', $vm->instanceType);
        $this->assertEqualsWithDelta(8.6, $vm->cpuBusySecondsTotal, 0.0001);
        $this->assertSame(1, $vm->effectiveCpus);
        $this->assertNull($vm->memoryTotalBytes);
        $this->assertNull($vm->memoryAvailableBytes);
        $this->assertNull($vm->filesystemTotalBytes);
        $this->assertNull($vm->filesystemAvailBytes);
        $this->assertSame(52428800, $vm->diskReadBytesTotal);
        $this->assertSame(16777216, $vm->diskWriteBytesTotal);
        $this->assertSame(4096, $vm->netRxBytesTotal);
        $this->assertSame(2048, $vm->netTxBytesTotal);
        $this->assertSame(0, $vm->procsTotal);
    }

    public function test_supports_lxd_prefix_multi_nic_and_multi_whole_disk_summation(): void
    {
        $payload = <<<'METRICS'
# HELP lxd_cpu_seconds_total The total number of CPU time used in seconds.
lxd_cpu_seconds_total{cpu="0",mode="user",name="vm-multi",project="default",type="virtual-machine"} 10.5
lxd_cpu_seconds_total{cpu="1",mode="system",name="vm-multi",project="default",type="virtual-machine"} 4.25
lxd_cpu_seconds_total{cpu="1",mode="idle",name="vm-multi",project="default",type="virtual-machine"} 100.0
lxd_cpu_effective_total{name="vm-multi",project="default",type="virtual-machine"} 0
lxd_disk_read_bytes_total{device="sda",name="vm-multi",project="default",type="virtual-machine"} 1000
lxd_disk_read_bytes_total{device="sda1",name="vm-multi",project="default",type="virtual-machine"} 900
lxd_disk_read_bytes_total{device="sdb",name="vm-multi",project="default",type="virtual-machine"} 2500
lxd_disk_read_bytes_total{device="sdb1",name="vm-multi",project="default",type="virtual-machine"} 2400
lxd_disk_read_bytes_total{device="nvme0n1",name="vm-multi",project="default",type="virtual-machine"} 3000
lxd_disk_read_bytes_total{device="nvme0n1p1",name="vm-multi",project="default",type="virtual-machine"} 2900
lxd_disk_read_bytes_total{device="zram0",name="vm-multi",project="default",type="virtual-machine"} 500
lxd_disk_written_bytes_total{device="sda",name="vm-multi",project="default",type="virtual-machine"} 400
lxd_disk_written_bytes_total{device="sda1",name="vm-multi",project="default",type="virtual-machine"} 350
lxd_disk_written_bytes_total{device="sdb",name="vm-multi",project="default",type="virtual-machine"} 600
lxd_disk_written_bytes_total{device="sdb1",name="vm-multi",project="default",type="virtual-machine"} 550
lxd_network_receive_bytes_total{device="lo",name="vm-multi",project="default",type="virtual-machine"} 99999
lxd_network_receive_bytes_total{device="eth0",name="vm-multi",project="default",type="virtual-machine"} 1200
lxd_network_receive_bytes_total{device="eth1",name="vm-multi",project="default",type="virtual-machine"} 800
lxd_network_transmit_bytes_total{device="lo",name="vm-multi",project="default",type="virtual-machine"} 88888
lxd_network_transmit_bytes_total{device="eth0",name="vm-multi",project="default",type="virtual-machine"} 300
lxd_network_transmit_bytes_total{device="eth1",name="vm-multi",project="default",type="virtual-machine"} 700
lxd_memory_MemTotal_bytes{name="vm-multi",project="default",type="virtual-machine"} 1000000
lxd_memory_MemFree_bytes{name="vm-multi",project="default",type="virtual-machine"} 250000
lxd_memory_Cached_bytes{name="vm-multi",project="default",type="virtual-machine"} 150000
lxd_filesystem_size_bytes{device="tmpfs",fstype="tmpfs",mountpoint="/",name="vm-multi",project="default",type="virtual-machine"} 999
lxd_filesystem_avail_bytes{device="tmpfs",fstype="tmpfs",mountpoint="/",name="vm-multi",project="default",type="virtual-machine"} 999
lxd_filesystem_size_bytes{device="/dev/sda1",fstype="ext4",mountpoint="/",name="vm-multi",project="default",type="virtual-machine"} 500000
lxd_filesystem_avail_bytes{device="/dev/sda1",fstype="ext4",mountpoint="/",name="vm-multi",project="default",type="virtual-machine"} 200000
lxd_procs_total{name="vm-multi",project="default",type="virtual-machine"} 42
METRICS;

        $parsed = $this->parser->parse($payload);

        $this->assertCount(1, $parsed);
        $this->assertArrayHasKey('default/vm-multi', $parsed);

        $vm = $parsed['default/vm-multi'];
        $this->assertEqualsWithDelta(14.75, $vm->cpuBusySecondsTotal, 0.0001);
        $this->assertSame(2, $vm->effectiveCpus);
        $this->assertSame(6500, $vm->diskReadBytesTotal);
        $this->assertSame(1000, $vm->diskWriteBytesTotal);
        $this->assertSame(2000, $vm->netRxBytesTotal);
        $this->assertSame(1000, $vm->netTxBytesTotal);
        $this->assertSame(1000000, $vm->memoryTotalBytes);
        $this->assertSame(400000, $vm->memoryAvailableBytes);
        $this->assertSame(500000, $vm->filesystemTotalBytes);
        $this->assertSame(200000, $vm->filesystemAvailBytes);
        $this->assertSame(42, $vm->procsTotal);
    }
}
