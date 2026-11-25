<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('vm_metric_samples', function (Blueprint $table): void {
            $table->unsignedBigInteger('virtual_machine_id');
            $table->unsignedInteger('bucket_ts');
            $table->unsignedTinyInteger('avail_mask')->default(0);
            $table->unsignedSmallInteger('cpu_usage_bp')->nullable();
            $table->unsignedBigInteger('ram_used_bytes')->nullable();
            $table->unsignedBigInteger('disk_used_bytes')->nullable();
            $table->unsignedBigInteger('disk_read_bps')->nullable();
            $table->unsignedBigInteger('disk_write_bps')->nullable();
            $table->unsignedBigInteger('net_rx_bps')->nullable();
            $table->unsignedBigInteger('net_tx_bps')->nullable();

            $table->primary(['virtual_machine_id', 'bucket_ts'], 'pk_vm_metric_samples');
            $table->index('bucket_ts', 'idx_vm_metric_samples_bucket_ts');
        });

        $rollupTables = [
            'vm_metric_rollups_5m' => [
                'pk' => 'pk_vm_metric_rollups_5m',
                'idx' => 'idx_vm_metric_rollups_5m_ts',
            ],
            'vm_metric_rollups_1h' => [
                'pk' => 'pk_vm_metric_rollups_1h',
                'idx' => 'idx_vm_metric_rollups_1h_ts',
            ],
        ];

        foreach ($rollupTables as $tableName => $indexes) {
            Schema::create($tableName, function (Blueprint $table) use ($indexes): void {
                $table->unsignedBigInteger('virtual_machine_id');
                $table->unsignedInteger('bucket_ts');
                $table->unsignedSmallInteger('sample_count')->default(1);
                $table->unsignedTinyInteger('avail_mask')->default(0);
                $table->unsignedSmallInteger('cpu_avg_bp')->nullable();
                $table->unsignedSmallInteger('cpu_min_bp')->nullable();
                $table->unsignedSmallInteger('cpu_max_bp')->nullable();
                $table->unsignedBigInteger('ram_avg_bytes')->nullable();
                $table->unsignedBigInteger('ram_min_bytes')->nullable();
                $table->unsignedBigInteger('ram_max_bytes')->nullable();
                $table->unsignedBigInteger('disk_avg_bytes')->nullable();
                $table->unsignedBigInteger('disk_min_bytes')->nullable();
                $table->unsignedBigInteger('disk_max_bytes')->nullable();
                $table->unsignedBigInteger('disk_read_avg_bps')->nullable();
                $table->unsignedBigInteger('disk_read_min_bps')->nullable();
                $table->unsignedBigInteger('disk_read_max_bps')->nullable();
                $table->unsignedBigInteger('disk_write_avg_bps')->nullable();
                $table->unsignedBigInteger('disk_write_min_bps')->nullable();
                $table->unsignedBigInteger('disk_write_max_bps')->nullable();
                $table->unsignedBigInteger('net_rx_avg_bps')->nullable();
                $table->unsignedBigInteger('net_rx_min_bps')->nullable();
                $table->unsignedBigInteger('net_rx_max_bps')->nullable();
                $table->unsignedBigInteger('net_tx_avg_bps')->nullable();
                $table->unsignedBigInteger('net_tx_min_bps')->nullable();
                $table->unsignedBigInteger('net_tx_max_bps')->nullable();

                $table->primary(['virtual_machine_id', 'bucket_ts'], $indexes['pk']);
                $table->index('bucket_ts', $indexes['idx']);
            });
        }

        Schema::create('vm_metric_totals', function (Blueprint $table): void {
            $table->unsignedBigInteger('virtual_machine_id');
            $table->unsignedInteger('recorded_at_ts');
            $table->unsignedSmallInteger('cpu_cores');
            $table->unsignedBigInteger('ram_total_bytes');
            $table->unsignedBigInteger('disk_total_bytes');

            $table->primary(['virtual_machine_id', 'recorded_at_ts'], 'pk_vm_metric_totals');
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            $nextDayEpoch = (intdiv(time(), 86400) + 1) * 86400;

            foreach (['vm_metric_samples', 'vm_metric_rollups_5m', 'vm_metric_rollups_1h'] as $tableName) {
                DB::statement(sprintf(
                    'ALTER TABLE `%s` PARTITION BY RANGE (bucket_ts) (PARTITION p_init VALUES LESS THAN (%d), PARTITION p_future VALUES LESS THAN MAXVALUE)',
                    $tableName,
                    $nextDayEpoch,
                ));
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vm_metric_totals');
        Schema::dropIfExists('vm_metric_rollups_1h');
        Schema::dropIfExists('vm_metric_rollups_5m');
        Schema::dropIfExists('vm_metric_samples');
    }
};
