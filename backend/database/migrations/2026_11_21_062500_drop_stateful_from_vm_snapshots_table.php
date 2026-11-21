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
        Schema::table('vm_snapshots', function (Blueprint $table): void {
            if (Schema::hasColumn('vm_snapshots', 'stateful')) {
                $table->dropColumn('stateful');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vm_snapshots', function (Blueprint $table): void {
            if (! Schema::hasColumn('vm_snapshots', 'stateful')) {
                $table->boolean('stateful')->default(false);
            }
        });
    }
};
