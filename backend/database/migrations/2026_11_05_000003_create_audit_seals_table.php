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
        Schema::create('audit_seals', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('from_id');
            $table->unsignedBigInteger('to_id');
            $table->char('hash', 64);
            $table->char('previous_hash', 64)->nullable();
            $table->char('hmac', 64);
            $table->string('key_id', 16)->default('v1');
            $table->dateTime('sealed_at', 6);
            $table->boolean('archived')->default(false);
            $table->char('archive_sha256', 64)->nullable();

            $table->unique('to_id', 'uk_audit_seals_to_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_seals');
    }
};
