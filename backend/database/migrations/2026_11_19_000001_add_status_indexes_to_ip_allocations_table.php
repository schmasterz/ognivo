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
        if (! Schema::hasIndex('ip_allocations', 'idx_ip_alloc_pool_status')) {
            Schema::table('ip_allocations', function (Blueprint $table): void {
                $table->index(['pool_id', 'status'], 'idx_ip_alloc_pool_status');
            });
        }

        if (! Schema::hasIndex('ip_allocations', 'idx_ip_alloc_user_status')) {
            Schema::table('ip_allocations', function (Blueprint $table): void {
                $table->index(['user_id', 'status'], 'idx_ip_alloc_user_status');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasIndex('ip_allocations', 'idx_ip_alloc_user_status')) {
            Schema::table('ip_allocations', function (Blueprint $table): void {
                $table->dropIndex('idx_ip_alloc_user_status');
            });
        }

        if (Schema::hasIndex('ip_allocations', 'idx_ip_alloc_pool_status')) {
            Schema::table('ip_allocations', function (Blueprint $table): void {
                $table->dropIndex('idx_ip_alloc_pool_status');
            });
        }
    }
};
