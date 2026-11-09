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
        Schema::create('ip_pools', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('host_id')->constrained('hosts')->cascadeOnDelete();
            $table->string('name');
            $table->string('cidr', 45);
            $table->string('first_usable', 45);
            $table->string('last_usable', 45);
            $table->string('network_mode', 20)->default('routed');
            $table->string('parent_interface', 32)->default('eth0');
            $table->json('excluded_ips')->nullable();
            $table->unsignedInteger('cooldown_seconds')->default(300);
            $table->boolean('active')->default(true);
            $table->string('subnet')->nullable();
            $table->string('gateway')->nullable();
            $table->json('dns_servers')->nullable();
            $table->unsignedTinyInteger('ip_version')->default(4);
            $table->boolean('is_public')->default(true);
            $table->unsignedInteger('vlan_id')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('ip_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pool_id')->constrained('ip_pools')->cascadeOnDelete();
            $table->binary('address', 16);
            $table->string('ip_string', 45);
            $table->unsignedBigInteger('virtual_machine_id')->nullable();
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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ip_allocations');
        Schema::dropIfExists('ip_pools');
    }
};
