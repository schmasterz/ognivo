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
            $table->timestamp('network_wait_started_at')->nullable();
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

        Schema::create('ip_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pool_id')->constrained('ip_pools')->cascadeOnDelete();
            $table->binary('address', 16);
            $table->string('ip_string', 45);
            $table->foreignId('virtual_machine_id')->nullable()->constrained('virtual_machines')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('mac_address', 17)->nullable();
            $table->string('status', 20)->default('allocated');
            $table->timestamp('allocated_at')->useCurrent();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->unique(['pool_id', 'address'], 'uk_pool_address');
            $table->index('virtual_machine_id', 'idx_ip_alloc_vm_id');
            $table->index('user_id', 'idx_ip_alloc_user_id');
            $table->index(['status', 'released_at'], 'idx_ip_alloc_status_released');
        });

        Schema::create('vm_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('virtual_machine_id')->constrained('virtual_machines')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->enum('source', ['manual', 'scheduled'])->default('manual');
            $table->enum('status', ['creating', 'ready', 'restoring', 'deleting', 'failed'])->default('creating');
            $table->boolean('stateful')->default(false);
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['virtual_machine_id', 'name'], 'vm_snapshots_vm_id_name_unique');
            $table->index('user_id', 'vm_snapshots_user_id_index');
            $table->index('status', 'vm_snapshots_status_index');
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

        Schema::create('cache', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->bigInteger('expiration')->index();
        });

        Schema::create('cache_locks', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->string('owner');
            $table->bigInteger('expiration')->index();
        });

        Schema::create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedSmallInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('connection');
            $table->string('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();

            $table->index(['connection', 'queue', 'failed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
        Schema::dropIfExists('drift_reports');
        Schema::dropIfExists('vm_snapshots');
        Schema::dropIfExists('ip_allocations');
        Schema::dropIfExists('virtual_machines');
    }
};
