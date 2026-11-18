<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Host;
use App\Models\Image;
use App\Models\IpPool;
use App\Models\QuotaGroup;
use App\Models\User;
use App\Models\UserQuota;
use App\Models\VirtualMachine;
use App\Services\AuthService;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

final class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new \RuntimeException('DatabaseSeeder cannot be run in production environment.');
        }

        // 1. Roles
        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $customerRole = Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        // 2. Quota Groups
        $standardTier = QuotaGroup::firstOrCreate(
            ['name' => 'Standard Tier'],
            [
                'description' => 'Default standard resource quota assigned to new user registrations',
                'is_default' => true,
                'max_vms' => 2,
                'max_cpu_cores' => 4,
                'max_ram_mb' => 4096,
                'max_storage_gb' => 80,
                'max_ips' => 1,
                'max_snapshots' => 3,
            ]
        );

        QuotaGroup::firstOrCreate(
            ['name' => 'Developer Tier'],
            [
                'description' => 'Expanded capacity for active developers and multi-service container stacks',
                'is_default' => false,
                'max_vms' => 8,
                'max_cpu_cores' => 16,
                'max_ram_mb' => 16384,
                'max_storage_gb' => 300,
                'max_ips' => 4,
                'max_snapshots' => 10,
            ]
        );

        QuotaGroup::firstOrCreate(
            ['name' => 'Enterprise Tier'],
            [
                'description' => 'Maximum resource allocations for production clustering and heavy compute workloads',
                'is_default' => false,
                'max_vms' => 32,
                'max_cpu_cores' => 64,
                'max_ram_mb' => 65536,
                'max_storage_gb' => 1500,
                'max_ips' => 16,
                'max_snapshots' => 30,
            ]
        );

        // 3. Allowed OS Images
        $defaultImages = [
            [
                'name' => 'ubuntu/24.04',
                'alias' => 'Ubuntu 24.04 LTS',
                'source' => 'https://images.linuxcontainers.org',
                'protocol' => 'simplestreams',
                'os_type' => 'linux',
                'enabled' => true,
            ],
            [
                'name' => 'debian/12',
                'alias' => 'Debian 12',
                'source' => 'https://images.linuxcontainers.org',
                'protocol' => 'simplestreams',
                'os_type' => 'linux',
                'enabled' => true,
            ],
            [
                'name' => 'alpine/3.20',
                'alias' => 'Alpine 3.20',
                'source' => 'https://images.linuxcontainers.org',
                'protocol' => 'simplestreams',
                'os_type' => 'linux',
                'enabled' => true,
            ],
        ];

        $imageIds = [];
        foreach ($defaultImages as $imgData) {
            $image = Image::firstOrCreate(['name' => $imgData['name']], $imgData);
            $imageIds[] = $image->id;
        }

        // 4. Default Admin User
        $authService = app(AuthService::class);
        $admin = User::firstOrCreate(
            ['email' => 'admin@ognivo.local'],
            [
                'name' => 'System Administrator',
                ...$authService->generateCredentials('password'),
            ]
        );
        $admin->assignRole($adminRole);

        UserQuota::firstOrCreate(
            ['user_id' => $admin->id],
            [
                'max_vms' => -1,
                'max_cpu_cores' => -1,
                'max_ram_mb' => -1,
                'max_storage_gb' => -1,
                'max_ips' => -1,
                'max_snapshots' => -1,
            ]
        );

        // 5. Default Demo Customer
        $customer = User::firstOrCreate(
            ['email' => 'customer@ognivo.local'],
            [
                'name' => 'Demo Customer',
                ...$authService->generateCredentials('password'),
                'quota_group_id' => $standardTier->id,
            ]
        );
        $customer->assignRole($customerRole);

        UserQuota::firstOrCreate(
            ['user_id' => $customer->id],
            [
                'quota_group_id' => $standardTier->id,
                'max_vms' => 3,
                'max_cpu_cores' => 6,
                'max_ram_mb' => 8192,
                'max_storage_gb' => 120,
                'max_ips' => 2,
                'max_snapshots' => 3,
            ]
        );

        // 6. Default Demo Hosts
        if (! app()->isProduction()) {
            $hosts = [
                Host::firstOrCreate(
                    ['name' => 'eu-west-1'],
                    [
                        'region' => 'Europe West (Frankfurt)',
                        'endpoint_url' => 'https://eu-west-1.incus.local:8443',
                        'driver' => 'fake',
                        'is_active' => true,
                        'total_cpu_cores' => 64,
                        'total_ram_mb' => 262144,
                        'total_storage_gb' => 8000,
                        'capacity_source' => 'discovered',
                        'capacity_synced_at' => now(),
                    ]
                ),
                Host::firstOrCreate(
                    ['name' => 'us-east-1'],
                    [
                        'region' => 'US East (N. Virginia)',
                        'endpoint_url' => 'https://us-east-1.incus.local:8443',
                        'driver' => 'fake',
                        'is_active' => true,
                        'total_cpu_cores' => 64,
                        'total_ram_mb' => 262144,
                        'total_storage_gb' => 8000,
                        'capacity_source' => 'discovered',
                        'capacity_synced_at' => now(),
                    ]
                ),
                Host::firstOrCreate(
                    ['name' => 'bg-sofia-1'],
                    [
                        'region' => 'Europe East (Sofia)',
                        'endpoint_url' => 'https://bg-sofia-1.incus.local:8443',
                        'driver' => 'fake',
                        'is_active' => true,
                        'total_cpu_cores' => 32,
                        'total_ram_mb' => 131072,
                        'total_storage_gb' => 4000,
                        'capacity_source' => 'discovered',
                        'capacity_synced_at' => now(),
                    ]
                ),
            ];

            foreach ($hosts as $host) {
                $host->images()->syncWithoutDetaching($imageIds);
            }
        }

        // 7. Default IP Pool
        $primaryHost = Host::where('is_active', true)->first() ?? Host::first();
        if ($primaryHost !== null) {
            IpPool::firstOrCreate(
                ['name' => 'Default Public IPv4 Pool'],
                [
                    'host_id' => $primaryHost->id,
                    'cidr' => '192.168.100.0/24',
                    'first_usable' => '192.168.100.2',
                    'last_usable' => '192.168.100.254',
                    'network_mode' => 'routed',
                    'parent_interface' => 'eth0',
                    'subnet' => '192.168.100.0/24',
                    'gateway' => '192.168.100.1',
                    'dns_servers' => ['1.1.1.1', '8.8.8.8'],
                    'excluded_ips' => ['192.168.100.1'],
                    'ip_version' => 4,
                    'cooldown_seconds' => 300,
                    'is_public' => true,
                    'active' => true,
                    'description' => 'Default IPv4 routed address pool for development',
                ]
            );
        }

        // 8. Default Development Instances (Containers & VMs)
        if (! app()->isProduction() && $primaryHost !== null) {
            VirtualMachine::firstOrCreate(
                ['name' => 'dev-container-01'],
                [
                    'user_id' => $customer->id,
                    'host_id' => $primaryHost->id,
                    'incus_project' => 'default',
                    'instance_type' => 'container',
                    'status' => 'running',
                    'cpu_cores' => 2,
                    'ram_mb' => 2048,
                    'disk_gb' => 20,
                    'os_image' => 'ubuntu/24.04',
                    'ipv4_address' => '192.168.100.10',
                ]
            );

            VirtualMachine::firstOrCreate(
                ['name' => 'dev-vm-01'],
                [
                    'user_id' => $customer->id,
                    'host_id' => $primaryHost->id,
                    'incus_project' => 'default',
                    'instance_type' => 'virtual-machine',
                    'status' => 'running',
                    'cpu_cores' => 2,
                    'ram_mb' => 2048,
                    'disk_gb' => 25,
                    'os_image' => 'debian/12',
                    'ipv4_address' => '192.168.100.11',
                ]
            );
        }
    }
}
