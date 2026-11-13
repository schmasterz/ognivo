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
        Schema::table('hosts', function (Blueprint $table): void {
            $table->string('capacity_source', 20)->default('unknown')->after('total_storage_gb');
            $table->timestamp('capacity_synced_at')->nullable()->after('capacity_source');
        });

        // Mark placeholder rows as unknown
        DB::table('hosts')
            ->where('total_cpu_cores', 32)
            ->where('total_ram_mb', 65536)
            ->where('total_storage_gb', 2000)
            ->update([
                'capacity_source' => 'unknown',
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hosts', function (Blueprint $table): void {
            $table->dropColumn(['capacity_source', 'capacity_synced_at']);
        });
    }
};
