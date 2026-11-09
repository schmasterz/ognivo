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
            $table->string('driver')->default('fake');
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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hosts');
        Schema::dropIfExists('panel_certificates');
    }
};
