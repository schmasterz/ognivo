<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('virtual_machines', function (Blueprint $table): void {
            $table->foreignId('vps_plan_version_id')
                ->nullable()
                ->after('host_id')
                ->constrained('vps_plan_versions')
                ->restrictOnDelete();
            $table->unsignedInteger('net_download_mbps')->nullable()->after('disk_gb');
            $table->unsignedInteger('net_upload_mbps')->nullable()->after('net_download_mbps');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('virtual_machines', function (Blueprint $table): void {
            $table->dropForeign(['vps_plan_version_id']);
        });

        Schema::table('virtual_machines', function (Blueprint $table): void {
            $table->dropColumn(['vps_plan_version_id', 'net_download_mbps', 'net_upload_mbps']);
        });
    }
};
