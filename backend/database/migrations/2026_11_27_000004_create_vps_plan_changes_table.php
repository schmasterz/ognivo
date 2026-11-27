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
        Schema::create('vps_plan_changes', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('virtual_machine_id')->constrained('virtual_machines')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('from_version_id')->nullable()->constrained('vps_plan_versions')->restrictOnDelete();
            $table->foreignId('to_version_id')->constrained('vps_plan_versions')->restrictOnDelete();
            $table->json('old_resources');
            $table->json('new_resources');
            $table->string('status', 32)->default('pending');
            $table->string('stage', 32)->nullable();
            $table->string('authorization_ref', 255)->nullable();
            $table->string('driver_name', 64)->nullable();
            $table->string('driver_operation_id', 255)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('apply_deadline')->nullable();
            $table->timestamps();

            $table->index(['virtual_machine_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);

            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $table->unsignedBigInteger('active_vm_id')
                    ->nullable()
                    ->virtualAs("CASE WHEN status IN ('pending','awaiting_authorization','applying','reconciliation_required') THEN virtual_machine_id ELSE NULL END");
                $table->unique('active_vm_id', 'vps_plan_changes_active_vm_id_unique');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vps_plan_changes');
    }
};
