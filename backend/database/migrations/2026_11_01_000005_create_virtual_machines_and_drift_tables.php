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
        Schema::create('virtual_machines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('host_id')->nullable()->constrained('hosts')->nullOnDelete();
            $table->string('name');
            $table->string('incus_project');
            $table->string('instance_type')->default('virtual-machine');
            $table->string('status')->default('provisioning');
            $table->string('network_status', 32)->nullable()->default(null);
            $table->unsignedInteger('cpu_cores');
            $table->unsignedInteger('ram_mb');
            $table->unsignedInteger('disk_gb');
            $table->string('os_image');
            $table->string('ipv4_address')->nullable();
            $table->string('ipv6_address')->nullable();
            $table->json('cloud_init')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'host_id', 'name'], 'virtual_machines_user_host_name_unique');
            $table->index('user_id', 'virtual_machines_user_id_index');
            $table->index(['user_id', 'status'], 'idx_vms_user_id_status');
            $table->index(['host_id', 'status'], 'idx_vms_host_id_status');
        });

        // Add foreign key constraint from ip_allocations to virtual_machines
        Schema::table('ip_allocations', function (Blueprint $table): void {
            $table->foreign('virtual_machine_id')
                ->references('id')
                ->on('virtual_machines')
                ->nullOnDelete();
        });

        Schema::create('drift_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('host_id')->constrained('hosts')->cascadeOnDelete();
            $table->foreignId('virtual_machine_id')->nullable()->constrained('virtual_machines')->nullOnDelete();
            $table->string('instance_name');
            $table->string('incus_project')->default('default');
            $table->string('kind')->index();
            $table->string('expected_state')->nullable();
            $table->string('actual_state')->nullable();
            $table->json('details')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['host_id', 'resolved_at'], 'drift_reports_host_id_resolved_at_index');
            $table->index(['instance_name', 'incus_project'], 'drift_reports_instance_name_incus_project_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('drift_reports');

        Schema::table('ip_allocations', function (Blueprint $table): void {
            $table->dropForeign(['virtual_machine_id']);
        });

        Schema::dropIfExists('virtual_machines');
    }
};
