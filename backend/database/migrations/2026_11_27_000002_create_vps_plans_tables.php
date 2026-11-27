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
        Schema::create('vps_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->text('description')->nullable();
            $table->string('status', 32)->default('draft');
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'sort_order']);
        });

        Schema::create('vps_plan_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vps_plan_id')->constrained('vps_plans')->restrictOnDelete();
            $table->unsignedInteger('version')->nullable();
            $table->string('status', 32)->default('draft');
            $table->unsignedInteger('cpu_cores');
            $table->unsignedInteger('ram_mb');
            $table->unsignedInteger('disk_gb');
            $table->string('instance_type', 32);
            $table->unsignedInteger('net_download_mbps')->nullable();
            $table->unsignedInteger('net_upload_mbps')->nullable();
            $table->text('change_summary')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('published_at')->nullable();

            $table->unique(['vps_plan_id', 'version']);
        });

        $this->addCheckConstraints();

        Schema::table('vps_plans', function (Blueprint $table): void {
            $table->foreign('current_version_id')
                ->references('id')
                ->on('vps_plan_versions')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vps_plans', function (Blueprint $table): void {
            $table->dropForeign(['current_version_id']);
        });

        Schema::dropIfExists('vps_plan_versions');
        Schema::dropIfExists('vps_plans');
    }

    /**
     * Enforce resource and network CHECK constraints on vps_plan_versions.
     */
    private function addCheckConstraints(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `vps_plan_versions` ADD CONSTRAINT `chk_vps_plan_versions_resources` CHECK (`cpu_cores` >= 1 AND `ram_mb` >= 512 AND `disk_gb` >= 5)');
            DB::statement('ALTER TABLE `vps_plan_versions` ADD CONSTRAINT `chk_vps_plan_versions_net_download` CHECK (`net_download_mbps` IS NULL OR (`net_download_mbps` >= 1 AND `net_download_mbps` <= 100000))');
            DB::statement('ALTER TABLE `vps_plan_versions` ADD CONSTRAINT `chk_vps_plan_versions_net_upload` CHECK (`net_upload_mbps` IS NULL OR (`net_upload_mbps` >= 1 AND `net_upload_mbps` <= 100000))');

            return;
        }

        if ($driver === 'sqlite') {
            $tableSql = DB::scalar("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'vps_plan_versions'");
            $indexRows = DB::select("SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = 'vps_plan_versions' AND sql IS NOT NULL");

            if (! is_string($tableSql)) {
                return;
            }

            $closingParen = strrpos($tableSql, ')');
            if ($closingParen === false) {
                return;
            }

            $checks = implode(', ', [
                'CONSTRAINT "chk_vps_plan_versions_resources" CHECK ("cpu_cores" >= 1 AND "ram_mb" >= 512 AND "disk_gb" >= 5)',
                'CONSTRAINT "chk_vps_plan_versions_net_download" CHECK ("net_download_mbps" IS NULL OR ("net_download_mbps" >= 1 AND "net_download_mbps" <= 100000))',
                'CONSTRAINT "chk_vps_plan_versions_net_upload" CHECK ("net_upload_mbps" IS NULL OR ("net_upload_mbps" >= 1 AND "net_upload_mbps" <= 100000))',
            ]);
            $rebuiltSql = substr($tableSql, 0, $closingParen).', '.$checks.substr($tableSql, $closingParen);

            DB::statement('DROP TABLE "vps_plan_versions"');
            DB::statement($rebuiltSql);

            foreach ($indexRows as $row) {
                if (isset($row->sql) && is_string($row->sql)) {
                    DB::statement($row->sql);
                }
            }
        }
    }
};
