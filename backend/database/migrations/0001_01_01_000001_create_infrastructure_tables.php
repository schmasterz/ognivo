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
        Schema::create('panel_certificates', function (Blueprint $table): void {
            $table->id();
            $table->text('certificate');
            $table->text('private_key');
            $table->string('fingerprint', 64)->index();
            $table->dateTime('not_before');
            $table->dateTime('not_after')->index();
            $table->string('status', 20)->default('active')->index();
            $table->unsignedTinyInteger('active_marker')->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('hosts', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('region')->nullable();
            $table->string('endpoint_url');
            $table->text('server_certificate')->nullable();
            $table->string('server_fingerprint', 64)->nullable();
            $table->string('server_public_key_pin', 255)->nullable();
            $table->string('driver')->default('incus');
            $table->text('token')->nullable();
            $table->string('status', 30)->default('ok');
            $table->boolean('needs_retrust')->default(false);
            $table->timestamp('last_checked_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('total_cpu_cores')->default(0);
            $table->unsignedInteger('total_ram_mb')->default(0);
            $table->unsignedInteger('total_storage_gb')->default(0);
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('images', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('source');
            $table->string('protocol')->default('simplestreams');
            $table->string('os_type')->default('linux');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('host_image', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('host_id')->constrained('hosts')->cascadeOnDelete();
            $table->foreignId('image_id')->constrained('images')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['host_id', 'image_id']);
        });

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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ip_pools');
        Schema::dropIfExists('host_image');
        Schema::dropIfExists('images');
        Schema::dropIfExists('hosts');
        Schema::dropIfExists('panel_certificates');
    }
};
