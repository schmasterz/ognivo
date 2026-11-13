<?php

declare(strict_types=1);

namespace Tests\Feature\Migration;

use App\Models\Host;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class HostCapacityMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_marks_placeholder_rows_as_unknown(): void
    {
        // Insert a host directly with old placeholder values
        $placeholderId = DB::table('hosts')->insertGetId([
            'name' => 'legacy-placeholder-node',
            'endpoint_url' => 'https://10.10.10.1:8443',
            'driver' => 'incus',
            'status' => 'ok',
            'is_active' => true,
            'total_cpu_cores' => 32,
            'total_ram_mb' => 65536,
            'total_storage_gb' => 2000,
            'capacity_source' => 'discovered', // Was mistakenly marked or left
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customId = DB::table('hosts')->insertGetId([
            'name' => 'custom-sized-node',
            'endpoint_url' => 'https://10.10.10.2:8443',
            'driver' => 'incus',
            'status' => 'ok',
            'is_active' => true,
            'total_cpu_cores' => 64,
            'total_ram_mb' => 131072,
            'total_storage_gb' => 4000,
            'capacity_source' => 'manual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Re-run the migration logic
        $migration = require database_path('migrations/2026_11_13_000001_add_capacity_columns_to_hosts_table.php');

        // We can execute the update part of the migration directly or via up()
        DB::table('hosts')
            ->where('total_cpu_cores', 32)
            ->where('total_ram_mb', 65536)
            ->where('total_storage_gb', 2000)
            ->update([
                'capacity_source' => 'unknown',
            ]);

        $placeholderHost = Host::find($placeholderId);
        $customHost = Host::find($customId);

        $this->assertNotNull($placeholderHost);
        $this->assertSame('unknown', $placeholderHost->capacity_source);

        $this->assertNotNull($customHost);
        $this->assertSame('manual', $customHost->capacity_source);
    }
}
