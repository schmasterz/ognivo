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
        if (! Schema::hasColumn('images', 'alias')) {
            Schema::table('images', function (Blueprint $table): void {
                $table->string('alias')->default('')->after('name');
            });

            // Backfill existing records if any
            DB::table('images')->where('alias', '')->update(['alias' => DB::raw('name')]);

            // Drop default if MySQL so column is strictly required
            if (DB::getDriverName() === 'mysql') {
                DB::statement('ALTER TABLE images ALTER COLUMN alias DROP DEFAULT');
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('images', 'alias')) {
            Schema::table('images', function (Blueprint $table): void {
                $table->dropColumn('alias');
            });
        }
    }
};
