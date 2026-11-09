<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (app()->bound('cache')) {
            cache()->flush();
        }

        $uses = array_flip(class_uses_recursive(static::class));
        if (isset($uses[RefreshDatabase::class])) {
            $this->seedTestingDefaults();
        }
    }

    private function seedTestingDefaults(): void
    {
        $now = now();

        DB::table('quota_groups')->insertOrIgnore([
            [
                'name' => 'Standard Tier',
                'description' => 'Default standard resource quota assigned to new user registrations',
                'is_default' => true,
                'max_vms' => 2,
                'max_cpu_cores' => 4,
                'max_ram_mb' => 4096,
                'max_storage_gb' => 80,
                'max_ips' => 1,
                'max_snapshots' => 3,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Developer Tier',
                'description' => 'Expanded capacity for active developers and multi-service container stacks',
                'is_default' => false,
                'max_vms' => 8,
                'max_cpu_cores' => 16,
                'max_ram_mb' => 16384,
                'max_storage_gb' => 300,
                'max_ips' => 4,
                'max_snapshots' => 10,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Enterprise Tier',
                'description' => 'Maximum resource allocations for production clustering and heavy compute workloads',
                'is_default' => false,
                'max_vms' => 32,
                'max_cpu_cores' => 64,
                'max_ram_mb' => 65536,
                'max_storage_gb' => 1500,
                'max_ips' => 16,
                'max_snapshots' => 30,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        foreach (['ubuntu/24.04', 'images:ubuntu/24.04', 'ubuntu:24.04', 'images:debian/12', 'debian:12', 'images:alpine/3.20', 'images:alpine/3.19'] as $imageName) {
            DB::table('images')->insertOrIgnore([
                'name' => $imageName,
                'source' => $imageName === 'ubuntu:24.04'
                    ? 'https://cloud-images.ubuntu.com/releases'
                    : 'https://images.linuxcontainers.org',
                'protocol' => 'simplestreams',
                'os_type' => 'linux',
                'enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
