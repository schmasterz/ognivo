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
        Schema::table('hosts', function (Blueprint $table): void {
            $table->decimal('cpu_overcommit', 5, 2)->default(1.00)->after('total_storage_gb');
            $table->decimal('ram_overcommit', 5, 2)->default(1.00)->after('cpu_overcommit');
            $table->decimal('disk_overcommit', 5, 2)->default(1.00)->after('ram_overcommit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hosts', function (Blueprint $table): void {
            $table->dropColumn(['cpu_overcommit', 'ram_overcommit', 'disk_overcommit']);
        });
    }
};
