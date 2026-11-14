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
        Schema::table('virtual_machines', function (Blueprint $table): void {
            $table->dropUnique('virtual_machines_user_host_name_unique');
            $table->index(['user_id', 'host_id', 'name'], 'virtual_machines_user_host_name_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('virtual_machines', function (Blueprint $table): void {
            $table->dropIndex('virtual_machines_user_host_name_index');
            $table->unique(['user_id', 'host_id', 'name'], 'virtual_machines_user_host_name_unique');
        });
    }
};
