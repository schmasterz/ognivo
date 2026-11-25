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
        Schema::table('hosts', function (Blueprint $table): void {
            $table->timestamp('metrics_last_success_at')->nullable()->after('capacity_synced_at');
            $table->timestamp('metrics_last_error_at')->nullable()->after('metrics_last_success_at');
            $table->string('metrics_last_error', 500)->nullable()->after('metrics_last_error_at');
            $table->unsignedInteger('metrics_last_duration_ms')->nullable()->after('metrics_last_error');
            $table->unsignedSmallInteger('metrics_consecutive_failures')->default(0)->after('metrics_last_duration_ms');
            $table->timestamp('metrics_backoff_until')->nullable()->after('metrics_consecutive_failures');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hosts', function (Blueprint $table): void {
            $table->dropColumn([
                'metrics_last_success_at',
                'metrics_last_error_at',
                'metrics_last_error',
                'metrics_last_duration_ms',
                'metrics_consecutive_failures',
                'metrics_backoff_until',
            ]);
        });
    }
};
