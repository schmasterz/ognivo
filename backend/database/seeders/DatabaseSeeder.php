<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Host;
use App\Models\IpPool;
use App\Models\User;
use App\Models\UserQuota;
use App\Models\VirtualMachine;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
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

        // 2. Default Admin User
        $admin = User::firstOrCreate(
            ['email' => 'admin@ognivo.local'],
            [
                'name' => 'System Administrator',
                'password' => Hash::make('password'),
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

        // 3. Default Demo Customer
        $customer = User::firstOrCreate(
            ['email' => 'customer@ognivo.local'],
            [
                'name' => 'Demo Customer',
                'password' => Hash::make('password'),
            ]
        );
        $customer->assignRole($customerRole);

        UserQuota::firstOrCreate(
            ['user_id' => $customer->id],
            [
                'max_vms' => 3,
                'max_cpu_cores' => 6,
                'max_ram_mb' => 8192,
                'max_storage_gb' => 120,
                'max_ips' => 2,
                'max_snapshots' => 3,
            ]
        );

        // 4. Default Demo Hosts (Using Fake Driver for local/testing development)
        if (! app()->isProduction()) {
            $euHost = Host::firstOrCreate(
                ['name' => 'eu-west-1'],
                [
                    'region' => 'Europe West (Frankfurt)',
                    'endpoint_url' => 'https://eu-west-1.incus.local:8443',
                    'driver' => 'fake',
                    'is_active' => true,
                    'total_cpu_cores' => 64,
                    'total_ram_mb' => 262144,
                    'total_storage_gb' => 8000,
                ]
            );

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
                ]
            );

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
                ]
            );

            Host::firstOrCreate(
                ['name' => 'node-1-development'],
                [
                    'endpoint_url' => 'https://127.0.0.1:8443',
                    'driver' => 'fake',
                    'is_active' => true,
                    'total_cpu_cores' => 32,
                    'total_ram_mb' => 65536,
                    'total_storage_gb' => 2000,
                ]
            );
        }

        // 5. Default IP Pool
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

        // 6. Default Development Instances (Containers & VMs)
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
                    'os_image' => 'images:ubuntu/24.04',
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
                    'os_image' => 'images:debian/12',
                    'ipv4_address' => '192.168.100.11',
                ]
            );
        }
    }
}
