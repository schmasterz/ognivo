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
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->dateTime('occurred_at', 6);
            $table->char('request_id', 36)->nullable();
            $table->unsignedBigInteger('operation_id')->nullable();
            $table->enum('actor_type', ['user', 'system', 'job']);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_label', 255)->nullable();
            $table->unsignedBigInteger('impersonator_id')->nullable();
            $table->unsignedBigInteger('tenant_user_id')->nullable();
            $table->string('action', 100);
            $table->enum('outcome', ['success', 'denied', 'failed']);
            $table->string('subject_type', 50)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->string('subject_label', 255)->nullable();
            $table->binary('ip', 16)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->enum('visibility', ['tenant', 'admin'])->default('admin');
            $table->json('context')->nullable();

            // Indexes
            $table->index(['tenant_user_id', 'visibility', 'occurred_at', 'id'], 'idx_audit_logs_tenant_vis_time_id');
            $table->index(['actor_id', 'occurred_at'], 'idx_audit_logs_actor_time');
            $table->index(['subject_type', 'subject_id', 'occurred_at'], 'idx_audit_logs_subject_time');
            $table->index(['action', 'occurred_at'], 'idx_audit_logs_action_time');
            $table->index('request_id', 'idx_audit_logs_request_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
