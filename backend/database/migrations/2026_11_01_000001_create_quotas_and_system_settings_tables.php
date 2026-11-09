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
        Schema::create('quota_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->boolean('is_default')->default(false)->index();
            $table->integer('max_vms')->default(2);
            $table->integer('max_cpu_cores')->default(4);
            $table->integer('max_ram_mb')->default(4096);
            $table->integer('max_storage_gb')->default(80);
            $table->integer('max_ips')->default(1);
            $table->integer('max_snapshots')->nullable()->default(3);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreign('quota_group_id')
                ->references('id')
                ->on('quota_groups')
                ->nullOnDelete();
        });

        Schema::create('user_quotas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('quota_group_id')->nullable()->constrained('quota_groups')->nullOnDelete();
            $table->integer('max_vms')->default(2);
            $table->integer('max_cpu_cores')->default(4);
            $table->integer('max_ram_mb')->default(4096);
            $table->integer('max_storage_gb')->default(80);
            $table->integer('max_ips')->default(1);
            $table->integer('max_snapshots')->nullable()->default(3);
            $table->timestamps();
        });

        Schema::create('system_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->json('value');
            $table->timestamps();
        });

        // Seed initial standard Quota Groups
        $now = now();

        DB::table('quota_groups')->insert([
            [
                'name' => 'Standard Tier',
                'description' => 'Default standard resource quota assigned to new user registrations',
                'is_default' => true,
                'max_vms' => 2,
                'max_cpu_cores' => 4,
                'max_ram_mb' => 4096,
                'max_storage_gb' => 80,
                'max_ips' => 1,
                'max_snapshots' => 3,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Developer Tier',
                'description' => 'Expanded capacity for active developers and multi-service container stacks',
                'is_default' => false,
                'max_vms' => 8,
                'max_cpu_cores' => 16,
                'max_ram_mb' => 16384,
                'max_storage_gb' => 300,
                'max_ips' => 4,
                'max_snapshots' => 10,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Enterprise Tier',
                'description' => 'Maximum resource allocations for production clustering and heavy compute workloads',
                'is_default' => false,
                'max_vms' => 32,
                'max_cpu_cores' => 64,
                'max_ram_mb' => 65536,
                'max_storage_gb' => 1500,
                'max_ips' => 16,
                'max_snapshots' => 30,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        // Seed default quota template if not already present
        DB::table('system_settings')->insertOrIgnore([
            'key' => 'default_quota',
            'value' => json_encode([
                'max_vms' => 2,
                'max_cpu_cores' => 4,
                'max_ram_mb' => 4096,
                'max_storage_gb' => 80,
                'max_ips' => 1,
                'max_snapshots' => 3,
            ], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('user_quotas');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['quota_group_id']);
        });

        Schema::dropIfExists('quota_groups');
    }
};
