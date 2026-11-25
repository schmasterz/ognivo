<?php

declare(strict_types=1);

namespace App\Services\Hypervisor;

use App\DTO\Incus\RawInstanceMetricsDTO;

/**
 * Parses OpenMetrics / Prometheus text exposition from Incus/LXD /1.0/metrics into per-instance DTOs.
 */
final class OpenMetricsParser
{
    /**
     * CPU modes counted toward busy CPU time (excludes idle and iowait).
     */
    public const BUSY_CPU_MODES = ['user', 'nice', 'system', 'irq', 'softirq', 'steal'];

    /**
     * Virtual/ephemeral filesystem types ignored when selecting the root (/) filesystem.
     */
    public const IGNORED_ROOT_FSTYPES = ['tmpfs', 'ramfs', 'devtmpfs'];

    /**
     * Parse raw OpenMetrics payload into RawInstanceMetricsDTO instances keyed by "{project}/{name}".
     *
     * @return array<string, RawInstanceMetricsDTO>
     */
    public function parse(string $payload): array
    {
        /** @var array<string, array{
         *     project: string,
         *     name: string,
         *     type: string,
         *     cpu_busy: float,
         *     has_cpu: bool,
         *     cpus: array<string, true>,
         *     cpu_effective: int,
         *     mem_total: int|null,
         *     mem_avail: int|null,
         *     mem_free: int|null,
         *     mem_cached: int|null,
         *     fs_total: int|null,
         *     fs_avail: int|null,
         *     root_device: string,
         *     disk_reads: array<string, int>,
         *     disk_writes: array<string, int>,
         *     net_rx: int,
         *     has_net_rx: bool,
         *     net_tx: int,
         *     has_net_tx: bool,
         *     procs: int,
         * }> $instances */
        $instances = [];

        foreach (explode("\n", $payload) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (preg_match('/^(?:incus|lxd)_([a-zA-Z0-9_]+)\{([^}]+)\}\s+([0-9.eE+-]+)$/', $trimmed, $matches) !== 1) {
                continue;
            }

            $metric = $matches[1];
            $labelsRaw = $matches[2];
            $val = $matches[3];

            $labelMatchCount = preg_match_all('/([a-zA-Z0-9_]+)="([^"]*)"/', $labelsRaw, $labelMatches, PREG_SET_ORDER);
            if ($labelMatchCount === false || $labelMatchCount === 0) {
                continue;
            }

            /** @var array<string, string> $labels */
            $labels = [];
            foreach ($labelMatches as $lm) {
                $labels[$lm[1]] = $lm[2];
            }

            $name = $labels['name'] ?? '';
            $project = $labels['project'] ?? '';
            if ($name === '' || $project === '') {
                continue;
            }

            $key = "{$project}/{$name}";
            $typeLabel = $labels['type'] ?? '';
            $inst = $instances[$key] ?? [
                'project' => $project,
                'name' => $name,
                'type' => $typeLabel !== '' ? $typeLabel : 'virtual-machine',
                'cpu_busy' => 0.0,
                'has_cpu' => false,
                'cpus' => [],
                'cpu_effective' => 0,
                'mem_total' => null,
                'mem_avail' => null,
                'mem_free' => null,
                'mem_cached' => null,
                'fs_total' => null,
                'fs_avail' => null,
                'root_device' => '',
                'disk_reads' => [],
                'disk_writes' => [],
                'net_rx' => 0,
                'has_net_rx' => false,
                'net_tx' => 0,
                'has_net_tx' => false,
                'procs' => 0,
            ];

            if ($typeLabel !== '') {
                $inst['type'] = $typeLabel;
            }

            switch ($metric) {
                case 'cpu_seconds_total':
                    $mode = $labels['mode'] ?? '';
                    if (in_array($mode, self::BUSY_CPU_MODES, true)) {
                        $inst['cpu_busy'] += (float) $val;
                        $inst['has_cpu'] = true;
                    }
                    $cpuLabel = $labels['cpu'] ?? '';
                    if ($cpuLabel !== '') {
                        $inst['cpus'][$cpuLabel] = true;
                    }
                    break;

                case 'cpu_effective_total':
                    $inst['cpu_effective'] = (int) round((float) $val);
                    break;

                case 'memory_MemTotal_bytes':
                    $inst['mem_total'] = (int) round((float) $val);
                    break;

                case 'memory_MemAvailable_bytes':
                    $inst['mem_avail'] = (int) round((float) $val);
                    break;

                case 'memory_MemFree_bytes':
                    $inst['mem_free'] = (int) round((float) $val);
                    break;

                case 'memory_Cached_bytes':
                    $inst['mem_cached'] = (int) round((float) $val);
                    break;

                case 'filesystem_size_bytes':
                    if (($labels['mountpoint'] ?? '') === '/' && ! in_array(strtolower($labels['fstype'] ?? ''), self::IGNORED_ROOT_FSTYPES, true)) {
                        $sizeBytes = (int) round((float) $val);
                        if ($sizeBytes > 0) {
                            $inst['fs_total'] = $sizeBytes;
                            $inst['root_device'] = $labels['device'] ?? '';
                        }
                    }
                    break;

                case 'filesystem_avail_bytes':
                    if (($labels['mountpoint'] ?? '') === '/' && ! in_array(strtolower($labels['fstype'] ?? ''), self::IGNORED_ROOT_FSTYPES, true)) {
                        $inst['fs_avail'] = (int) round((float) $val);
                    }
                    break;

                case 'disk_read_bytes_total':
                    $device = $labels['device'] ?? '';
                    if ($device !== '') {
                        $inst['disk_reads'][$device] = (int) round((float) $val);
                    }
                    break;

                case 'disk_written_bytes_total':
                    $device = $labels['device'] ?? '';
                    if ($device !== '') {
                        $inst['disk_writes'][$device] = (int) round((float) $val);
                    }
                    break;

                case 'network_receive_bytes_total':
                    $netDevice = $labels['device'] ?? '';
                    if ($netDevice !== '' && $netDevice !== 'lo') {
                        $inst['net_rx'] += (int) round((float) $val);
                        $inst['has_net_rx'] = true;
                    }
                    break;

                case 'network_transmit_bytes_total':
                    $netDevice = $labels['device'] ?? '';
                    if ($netDevice !== '' && $netDevice !== 'lo') {
                        $inst['net_tx'] += (int) round((float) $val);
                        $inst['has_net_tx'] = true;
                    }
                    break;

                case 'procs_total':
                    $inst['procs'] = (int) round((float) $val);
                    break;
            }

            $instances[$key] = $inst;
        }

        $result = [];
        foreach ($instances as $key => $inst) {
            $effectiveCpus = $inst['cpu_effective'] > 0 ? $inst['cpu_effective'] : max(1, count($inst['cpus']));
            $memTotal = $inst['mem_total'];
            $memAvail = $memTotal !== null
                ? ($inst['mem_avail'] ?? ($inst['mem_free'] !== null ? min($memTotal, $inst['mem_free'] + ($inst['mem_cached'] ?? 0)) : null))
                : null;
            $fsTotal = ($inst['fs_total'] !== null && $inst['fs_total'] > 0) ? $inst['fs_total'] : null;
            $fsAvail = ($fsTotal !== null && $inst['fs_avail'] !== null) ? min($fsTotal, max(0, $inst['fs_avail'])) : null;

            $result[$key] = new RawInstanceMetricsDTO(
                project: $inst['project'],
                name: $inst['name'],
                instanceType: $inst['type'],
                cpuBusySecondsTotal: $inst['has_cpu'] ? $inst['cpu_busy'] : null,
                effectiveCpus: $effectiveCpus,
                memoryTotalBytes: $memTotal,
                memoryAvailableBytes: $memAvail,
                filesystemTotalBytes: $fsTotal,
                filesystemAvailBytes: $fsAvail,
                diskReadBytesTotal: $this->sumWholeDiskBytes($inst['disk_reads']),
                diskWriteBytesTotal: $this->sumWholeDiskBytes($inst['disk_writes']),
                netRxBytesTotal: $inst['has_net_rx'] ? $inst['net_rx'] : null,
                netTxBytesTotal: $inst['has_net_tx'] ? $inst['net_tx'] : null,
                procsTotal: $inst['procs'],
            );
        }

        return $result;
    }

    /**
     * Sum block device bytes across whole disks while excluding virtual devices and partitions when parent disk exists.
     *
     * @param  array<string, int>  $deviceBytes
     */
    private function sumWholeDiskBytes(array $deviceBytes): ?int
    {
        if ($deviceBytes === []) {
            return null;
        }

        /** @var array<string, int> $filtered */
        $filtered = [];
        foreach ($deviceBytes as $dev => $bytes) {
            if (preg_match('/^(?:loop|ram|sr|fd|zram)\d+$/i', $dev) === 1) {
                continue;
            }
            $filtered[$dev] = $bytes;
        }

        if ($filtered === []) {
            return 0;
        }

        $sum = 0;
        foreach ($filtered as $dev => $bytes) {
            if (preg_match('/^([a-z]+)\d+$/i', $dev, $m) === 1 && array_key_exists($m[1], $filtered)) {
                continue;
            }
            if (preg_match('/^(nvme\d+n\d+)p\d+$/i', $dev, $m) === 1 && array_key_exists($m[1], $filtered)) {
                continue;
            }
            $sum += $bytes;
        }

        return $sum;
    }
}
