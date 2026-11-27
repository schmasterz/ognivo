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
        Schema::table('idempotency_keys', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
            $table->dropUnique('idempotency_keys_key_unique');
            $table->unique(['user_id', 'key'], 'idempotency_keys_user_id_key_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
        });

        Schema::table('idempotency_keys', function (Blueprint $table): void {
            $table->dropUnique('idempotency_keys_user_id_key_unique');
            $table->dropColumn('user_id');
            $table->unique('key', 'idempotency_keys_key_unique');
        });
    }
};
