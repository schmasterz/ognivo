<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\SmtpTestMail;
use App\Models\Host;
use App\Models\IpAllocation;
use App\Models\IpPool;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserQuota;
use App\Models\VirtualMachine;
use App\States\Vm\Running;
use App\States\Vm\Stopped;
use App\States\Vm\Suspended;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AdminUserAndPoolManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    private Host $host;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $this->host = Host::firstOrCreate(
            ['name' => 'admin-test-node'],
            [
                'endpoint_url' => 'https://127.0.0.1:8443',
                'driver' => 'fake',
                'is_active' => true,
                'total_cpu_cores' => 64,
                'total_ram_mb' => 131072,
                'total_storage_gb' => 4000,
                'capacity_source' => 'discovered',
                'capacity_synced_at' => now(),
            ]
        );

        $this->admin = User::factory()->create(['name' => 'Admin User', 'email' => 'admin@test.local']);
        $this->admin->assignRole('admin');

        $this->customer = User::factory()->create(['name' => 'Customer User', 'email' => 'customer@test.local']);
        $this->customer->assignRole('customer');

        UserQuota::create([
            'user_id' => $this->customer->id,
            'max_vms' => 2,
            'max_cpu_cores' => 4,
            'max_ram_mb' => 4096,
            'max_storage_gb' => 80,
            'max_ips' => 1,
        ]);
    }

    public function test_admin_can_create_user_with_infinite_quotas(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/users', [
                'name' => 'Super Developer',
                'email' => 'superdev@test.local',
                'password' => 'SuperSecret123!',
                'role' => 'customer',
                'quota' => [
                    'max_vms' => -1,
                    'max_cpu_cores' => -1,
                    'max_ram_mb' => -1,
                    'max_storage_gb' => -1,
                    'max_ips' => -1,
                ],
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('user.email', 'superdev@test.local');
        $response->assertJsonPath('user.quota.max_vms', -1);

        $this->assertDatabaseHas('users', ['email' => 'superdev@test.local']);
        $this->assertDatabaseHas('user_quotas', [
            'max_vms' => -1,
            'max_cpu_cores' => -1,
        ]);
    }

    public function test_admin_can_update_user_and_quota(): void
    {
        $response = $this->actingAs($this->admin)
            ->putJson(sprintf('/api/v1/admin/users/%d', $this->customer->id), [
                'name' => 'Updated Customer Name',
                'email' => 'customer-updated@test.local',
                'role' => 'customer',
                'quota' => [
                    'max_vms' => 10,
                    'max_cpu_cores' => 24,
                    'max_ram_mb' => 32768,
                    'max_storage_gb' => 500,
                    'max_ips' => 8,
                ],
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'id' => $this->customer->id,
            'name' => 'Updated Customer Name',
            'email' => 'customer-updated@test.local',
        ]);
        $this->assertDatabaseHas('user_quotas', [
            'user_id' => $this->customer->id,
            'max_vms' => 10,
            'max_cpu_cores' => 24,
        ]);
    }

    public function test_admin_cannot_delete_self(): void
    {
        $response = $this->actingAs($this->admin)
            ->deleteJson(sprintf('/api/v1/admin/users/%d', $this->admin->id));

        $response->assertStatus(422);
    }

    public function test_admin_can_delete_customer(): void
    {
        $response = $this->actingAs($this->admin)
            ->deleteJson(sprintf('/api/v1/admin/users/%d', $this->customer->id));

        $response->assertStatus(202);
        $this->assertDatabaseMissing('users', ['id' => $this->customer->id]);
    }

    public function test_admin_can_manage_default_quota(): void
    {
        $getResponse = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/quota/default');

        $getResponse->assertStatus(200);

        $updateResponse = $this->actingAs($this->admin)
            ->putJson('/api/v1/admin/quota/default', [
                'max_vms' => 5,
                'max_cpu_cores' => 10,
                'max_ram_mb' => 8192,
                'max_storage_gb' => 160,
                'max_ips' => 3,
            ]);

        $updateResponse->assertStatus(200);
        $updateResponse->assertJsonPath('quota.max_vms', 5);
        $this->assertEquals(5, SystemSetting::getDefaultQuota()['max_vms']);
    }

    public function test_admin_can_create_ip_pool_with_auto_generation(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/ip-pools', [
                'name' => 'Public IPv4 DMZ',
                'host_id' => $this->host->id,
                'cidr' => '198.51.100.0/29', // 6 usable IPs (.1 to .6)
                'gateway' => '198.51.100.1',
                'parent_interface' => 'eth0',
                'dns_servers' => ['8.8.8.8', '1.1.1.1'],
                'ip_version' => 4,
                'is_public' => true,
            ]);

        $response->assertStatus(201);
        $poolId = (int) $response->json('pool.id');

        $this->assertDatabaseHas('ip_pools', [
            'name' => 'Public IPv4 DMZ',
            'cidr' => '198.51.100.0/29',
        ]);

        $this->assertEquals(5, $response->json('pool.utilization.total'));
    }

    public function test_admin_cannot_delete_ip_pool_with_active_ips(): void
    {
        /** @var IpPool $pool */
        $pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Active Pool',
            'cidr' => '10.50.0.0/24',
            'subnet' => '10.50.0.0/24',
            'first_usable' => '10.50.0.1',
            'last_usable' => '10.50.0.254',
            'ip_version' => 4,
        ]);

        IpAllocation::create([
            'pool_id' => $pool->id,
            'address' => inet_pton('10.50.0.10'),
            'ip_string' => '10.50.0.10',
            'status' => 'allocated',
            'user_id' => $this->customer->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->deleteJson(sprintf('/api/v1/admin/ip-pools/%d', $pool->id));

        $response->assertStatus(422);
    }

    public function test_admin_can_delete_empty_ip_pool(): void
    {
        /** @var IpPool $pool */
        $pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Empty Pool',
            'cidr' => '10.60.0.0/24',
            'subnet' => '10.60.0.0/24',
            'first_usable' => '10.60.0.1',
            'last_usable' => '10.60.0.254',
            'ip_version' => 4,
        ]);

        $response = $this->actingAs($this->admin)
            ->deleteJson(sprintf('/api/v1/admin/ip-pools/%d', $pool->id));

        $response->assertStatus(200);
        $this->assertDatabaseMissing('ip_pools', ['id' => $pool->id]);
    }

    public function test_admin_can_toggle_registration_and_register_is_blocked_when_disabled(): void
    {
        // 1. Initial state: public endpoint returns registration enabled
        $pubRes = $this->getJson('/api/v1/settings/public');
        $pubRes->assertOk();
        $pubRes->assertJson(['registration_enabled' => true]);

        // 2. Admin disables registration
        $updateRes = $this->actingAs($this->admin)->putJson('/api/v1/admin/settings', [
            'registration_enabled' => false,
        ]);
        $updateRes->assertOk();
        $updateRes->assertJsonPath('settings.registration_enabled', false);

        // 3. New registration is rejected with 403 Forbidden
        $regRes = $this->postJson('/api/v1/register', [
            'name' => 'Blocked User',
            'email' => 'blocked@example.com',
            'password' => 'SecurePass123!#',
        ]);
        $regRes->assertForbidden();

        // 4. Admin re-enables registration
        $reEnableRes = $this->actingAs($this->admin)->putJson('/api/v1/admin/settings', [
            'registration_enabled' => true,
        ]);
        $reEnableRes->assertOk();

        // 5. Registration succeeds
        $allowRes = $this->postJson('/api/v1/register', [
            'name' => 'Allowed User',
            'email' => 'allowed@example.com',
            'password' => 'SecurePass123!#',
        ]);
        $allowRes->assertCreated();
    }

    public function test_admin_can_toggle_virtualization_modes_and_cannot_disable_both(): void
    {
        // 1. Initial state: both are enabled
        $pubRes = $this->getJson('/api/v1/settings/public');
        $pubRes->assertOk();
        $pubRes->assertJson([
            'registration_enabled' => true,
            'allow_virtual_machines' => true,
            'allow_containers' => true,
        ]);

        // 2. Admin disables containers (only full VM allowed)
        $res1 = $this->actingAs($this->admin)->putJson('/api/v1/admin/settings', [
            'allow_virtual_machines' => true,
            'allow_containers' => false,
        ]);
        $res1->assertOk();
        $res1->assertJsonPath('settings.allow_virtual_machines', true);
        $res1->assertJsonPath('settings.allow_containers', false);

        // 3. Admin tries to disable BOTH virtualization modes -> 422 Unprocessable Content
        $resInvalid = $this->actingAs($this->admin)->putJson('/api/v1/admin/settings', [
            'allow_virtual_machines' => false,
            'allow_containers' => false,
        ]);
        $resInvalid->assertStatus(422);
        $resInvalid->assertJsonValidationErrors(['virtualization_modes']);

        // 4. Admin enables containers and disables full VM
        $res2 = $this->actingAs($this->admin)->putJson('/api/v1/admin/settings', [
            'allow_virtual_machines' => false,
            'allow_containers' => true,
        ]);
        $res2->assertOk();
        $res2->assertJsonPath('settings.allow_virtual_machines', false);
        $res2->assertJsonPath('settings.allow_containers', true);
    }

    public function test_user_cannot_provision_disabled_virtualization_mode(): void
    {
        // Disable full VMs on platform
        $this->actingAs($this->admin)->putJson('/api/v1/admin/settings', [
            'allow_virtual_machines' => false,
            'allow_containers' => true,
        ]);

        // Customer attempts to provision full virtual-machine
        $res = $this->actingAs($this->customer)->postJson('/api/v1/vms', [
            'name' => 'vm-not-allowed',
            'instance_type' => 'virtual-machine',
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu:24.04',
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['instance_type']);

        IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'admin-test-pool',
            'cidr' => '10.200.0.0/24',
            'first_usable' => '10.200.0.10',
            'last_usable' => '10.200.0.250',
            'active' => true,
        ]);

        // Customer provisions allowed container -> succeeds
        $resOk = $this->actingAs($this->customer)->postJson('/api/v1/vms', [
            'name' => 'container-allowed',
            'instance_type' => 'container',
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu:24.04',
        ]);

        $resOk->assertStatus(202);
    }

    public function test_suspending_user_automatically_suspends_all_their_vms(): void
    {
        $vmRunning = VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $this->host->id,
            'name' => 'cust-vm-running',
            'incus_project' => 'user-'.$this->customer->id,
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'ubuntu:24.04',
            'ipv4_address' => '10.10.10.25',
        ]);

        $vmStopped = VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $this->host->id,
            'name' => 'cust-vm-stopped',
            'incus_project' => 'user-'.$this->customer->id,
            'instance_type' => 'container',
            'status' => Stopped::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu:24.04',
            'ipv4_address' => '10.10.10.26',
        ]);

        // Admin cannot suspend their own account
        $selfRes = $this->actingAs($this->admin)->postJson(
            sprintf('/api/v1/admin/users/%d/status', $this->admin->id),
            ['status' => 'suspended']
        );
        $selfRes->assertStatus(422);

        // Admin suspends customer account via status endpoint
        $res = $this->actingAs($this->admin)->postJson(
            sprintf('/api/v1/admin/users/%d/status', $this->customer->id),
            ['status' => 'suspended']
        );

        $res->assertOk();
        $res->assertJsonPath('user.status', 'suspended');

        $this->assertDatabaseHas('users', [
            'id' => $this->customer->id,
            'status' => 'suspended',
        ]);

        // Both VMs belonging to customer must now be suspended
        $vmRunning->refresh();
        $vmStopped->refresh();
        $this->assertTrue($vmRunning->status->equals(Suspended::class));
        $this->assertTrue($vmStopped->status->equals(Suspended::class));
    }

    public function test_admin_can_filter_users_by_status_user_id_host_id_and_ip(): void
    {
        $host = Host::create([
            'name' => 'eu-central-node-1',
            'region' => 'Frankfurt',
            'endpoint_url' => 'https://10.0.0.1:8443',
            'driver' => 'incus',
            'is_active' => true,
            'total_cpu_cores' => 64,
            'total_ram_mb' => 131072,
            'total_storage_gb' => 2000,
            'capacity_source' => 'discovered',
            'capacity_synced_at' => now(),
        ]);

        $suspendedUser = User::factory()->create([
            'name' => 'Suspended Tenant',
            'email' => 'suspended@test.local',
            'status' => 'suspended',
        ]);
        $suspendedUser->assignRole('customer');

        VirtualMachine::create([
            'user_id' => $suspendedUser->id,
            'host_id' => $host->id,
            'name' => 'susp-vm-1',
            'incus_project' => 'user-'.$suspendedUser->id,
            'instance_type' => 'virtual-machine',
            'status' => Suspended::class,
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'ubuntu:24.04',
            'ipv4_address' => '192.0.2.99',
        ]);

        // 1. Filter by status=suspended
        $resStatus = $this->actingAs($this->admin)->getJson('/api/v1/admin/users?status=suspended');
        $resStatus->assertOk();
        $resStatus->assertJsonCount(1, 'data');
        $resStatus->assertJsonPath('data.0.email', 'suspended@test.local');

        // 2. Filter by status=active
        $resActive = $this->actingAs($this->admin)->getJson('/api/v1/admin/users?status=active');
        $resActive->assertOk();
        $resActive->assertJsonCount(2, 'data'); // admin + customer

        // 3. Filter by host_id
        $resHost = $this->actingAs($this->admin)->getJson('/api/v1/admin/users?host_id='.$host->id);
        $resHost->assertOk();
        $resHost->assertJsonCount(1, 'data');
        $resHost->assertJsonPath('data.0.id', $suspendedUser->id);

        // 4. Filter by IP search
        $resSearch = $this->actingAs($this->admin)->getJson('/api/v1/admin/users?search=192.0.2.99');
        $resSearch->assertOk();
        $resSearch->assertJsonCount(1, 'data');
        $resSearch->assertJsonPath('data.0.id', $suspendedUser->id);

        // 5. Filter by user_id
        $resUser = $this->actingAs($this->admin)->getJson('/api/v1/admin/users?user_id='.$this->customer->id);
        $resUser->assertOk();
        $resUser->assertJsonCount(1, 'data');
        $resUser->assertJsonPath('data.0.id', $this->customer->id);
    }

    public function test_server_side_pagination_works_for_users_vms_and_ips(): void
    {
        // Create 3 additional users (total 5 users: admin + customer + 3 new)
        for ($i = 1; $i <= 3; $i++) {
            $u = User::factory()->create([
                'name' => 'Paginated User '.$i,
                'email' => sprintf('puser%d@test.local', $i),
            ]);
            $u->assignRole('customer');
        }

        // Users pagination: per_page=2, page=1 and page=2
        $usersPage1 = $this->actingAs($this->admin)->getJson('/api/v1/admin/users?per_page=2&page=1');
        $usersPage1->assertOk();
        $usersPage1->assertJsonCount(2, 'data');
        $usersPage1->assertJsonPath('meta.current_page', 1);
        $usersPage1->assertJsonPath('meta.last_page', 3);
        $usersPage1->assertJsonPath('meta.total', 5);

        $usersPage2 = $this->actingAs($this->admin)->getJson('/api/v1/admin/users?per_page=2&page=2');
        $usersPage2->assertOk();
        $usersPage2->assertJsonCount(2, 'data');
        $usersPage2->assertJsonPath('meta.current_page', 2);

        // VMs pagination: create 3 VMs, request per_page=2
        for ($i = 1; $i <= 3; $i++) {
            VirtualMachine::create([
                'user_id' => $this->customer->id,
                'name' => 'page-vm-'.$i,
                'incus_project' => 'user-'.$this->customer->id,
                'instance_type' => 'container',
                'status' => Running::class,
                'cpu_cores' => 1,
                'ram_mb' => 1024,
                'disk_gb' => 10,
                'os_image' => 'ubuntu:24.04',
            ]);
        }

        $vmsPage1 = $this->actingAs($this->customer)->getJson('/api/v1/vms?per_page=2&page=1');
        $vmsPage1->assertOk();
        $vmsPage1->assertJsonCount(2, 'data');
        $vmsPage1->assertJsonPath('meta.current_page', 1);
        $vmsPage1->assertJsonPath('meta.last_page', 2);
        $vmsPage1->assertJsonPath('meta.total', 3);

        // IPs pagination: create pool with 3 IPs, request per_page=2
        /** @var IpPool $pool */
        $pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Paginated Pool',
            'cidr' => '10.99.0.0/24',
            'subnet' => '10.99.0.0/24',
            'first_usable' => '10.99.0.2',
            'last_usable' => '10.99.0.254',
            'gateway' => '10.99.0.1',
            'parent_interface' => 'eth0',
            'ip_version' => 4,
        ]);

        for ($i = 1; $i <= 3; $i++) {
            IpAllocation::create([
                'pool_id' => $pool->id,
                'user_id' => $this->customer->id,
                'address' => inet_pton('10.99.0.'.$i),
                'ip_string' => '10.99.0.'.$i,
                'status' => 'allocated',
                'allocated_at' => now(),
            ]);
        }

        $ipsPage1 = $this->actingAs($this->customer)->getJson('/api/v1/ips?per_page=2&page=1');
        $ipsPage1->assertOk();
        $ipsPage1->assertJsonCount(2, 'data');
        $ipsPage1->assertJsonPath('meta.current_page', 1);
        $ipsPage1->assertJsonPath('meta.last_page', 2);
        $ipsPage1->assertJsonPath('meta.total', 3);
    }

    public function test_admin_can_configure_and_retrieve_smtp_settings(): void
    {
        $payload = [
            'smtp' => [
                'enabled' => true,
                'host' => 'smtp.mailgun.org',
                'port' => 587,
                'encryption' => 'tls',
                'username' => 'postmaster@mg.example.com',
                'password' => 'secret-smtp-pass',
                'from_address' => 'noreply@example.com',
                'from_name' => 'LXD Cloud Admin',
            ],
        ];

        $res = $this->actingAs($this->admin)->putJson('/api/v1/admin/settings', $payload);
        $res->assertOk();
        $res->assertJsonPath('settings.smtp.enabled', true);
        $res->assertJsonPath('settings.smtp.host', 'smtp.mailgun.org');
        $res->assertJsonPath('settings.smtp.port', 587);
        $res->assertJsonPath('settings.smtp.encryption', 'tls');
        $res->assertJsonPath('settings.smtp.username', 'postmaster@mg.example.com');
        $res->assertJsonPath('settings.smtp.has_password', true);
        $res->assertJsonPath('settings.smtp.from_address', 'noreply@example.com');
        $res->assertJsonPath('settings.smtp.from_name', 'LXD Cloud Admin');
        // Ensure raw password is NOT exposed in response
        $this->assertArrayNotHasKey('password', $res->json('settings.smtp'));

        // Retrieve settings via GET
        $getRes = $this->actingAs($this->admin)->getJson('/api/v1/admin/settings');
        $getRes->assertOk();
        $getRes->assertJsonPath('settings.smtp.host', 'smtp.mailgun.org');
        $getRes->assertJsonPath('settings.smtp.has_password', true);
    }

    public function test_admin_can_send_test_email(): void
    {
        Mail::fake();

        // 1. Configure SMTP
        $this->actingAs($this->admin)->putJson('/api/v1/admin/settings', [
            'smtp' => [
                'enabled' => true,
                'host' => 'smtp.example.com',
                'port' => 587,
                'encryption' => 'tls',
                'username' => 'user',
                'password' => 'pass',
                'from_address' => 'noreply@example.com',
                'from_name' => 'LXD Platform',
            ],
        ]);

        // 2. Request test email
        $res = $this->actingAs($this->admin)->postJson('/api/v1/admin/settings/test-smtp', [
            'recipient_email' => 'recipient@example.com',
        ]);

        $res->assertOk();
        $res->assertJson(['success' => true]);

        Mail::assertSent(SmtpTestMail::class, function (SmtpTestMail $mail): bool {
            return $mail->hasTo('recipient@example.com') && $mail->serverHost === 'smtp.example.com';
        });
    }

    public function test_customer_cannot_access_smtp_settings(): void
    {
        $res = $this->actingAs($this->customer)->getJson('/api/v1/admin/settings');
        $res->assertStatus(403);

        $putRes = $this->actingAs($this->customer)->putJson('/api/v1/admin/settings', [
            'smtp' => ['enabled' => true],
        ]);
        $putRes->assertStatus(403);

        $testRes = $this->actingAs($this->customer)->postJson('/api/v1/admin/settings/test-smtp', [
            'recipient_email' => 'test@example.com',
        ]);
        $testRes->assertStatus(403);
    }
}
