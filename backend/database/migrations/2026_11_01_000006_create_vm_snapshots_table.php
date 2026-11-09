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
        if (! Schema::hasColumn('quota_groups', 'max_snapshots')) {
            Schema::table('quota_groups', function (Blueprint $table): void {
                $table->integer('max_snapshots')->nullable()->default(3)->after('max_ips');
            });

            DB::table('quota_groups')->where('name', 'Standard Tier')->update(['max_snapshots' => 3]);
            DB::table('quota_groups')->where('name', 'Developer Tier')->update(['max_snapshots' => 10]);
            DB::table('quota_groups')->where('name', 'Enterprise Tier')->update(['max_snapshots' => 30]);
        }

        if (! Schema::hasColumn('user_quotas', 'max_snapshots')) {
            Schema::table('user_quotas', function (Blueprint $table): void {
                $table->integer('max_snapshots')->nullable()->default(3)->after('max_ips');
            });
        }

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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vm_snapshots');
    }
};
