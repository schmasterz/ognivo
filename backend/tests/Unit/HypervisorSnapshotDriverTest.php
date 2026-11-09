<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Host;
use App\Models\PanelCertificate;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\Hypervisor\Drivers\FakeHypervisorDriver;
use App\Services\Hypervisor\Drivers\IncusDriver;
use App\Services\Hypervisor\Drivers\LxdDriver;
use App\Services\Hypervisor\Exceptions\FingerprintMismatchException;
use App\Services\Hypervisor\HypervisorGateway;
use App\Services\Hypervisor\HypervisorManager;
use App\Services\PanelCertificateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

final class HypervisorSnapshotDriverTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Host $incusHost;

    private Host $lxdHost;

    private VirtualMachine $vm;

    private PanelCertificate $cert;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'project_name' => 'tenant-snap-proj',
        ]);

        $this->cert = app(PanelCertificateService::class)->generate(365);

        $this->incusHost = Host::create([
            'name' => 'incus-snap-node',
            'endpoint_url' => 'https://incus-snap.local:8443',
            'driver' => 'incus',
            'server_public_key_pin' => 'sha256//'.base64_encode(str_repeat('p', 32)),
            'is_active' => true,
        ]);

        $this->lxdHost = Host::create([
            'name' => 'lxd-snap-node',
            'endpoint_url' => 'https://lxd-snap.local:8443',
            'driver' => 'lxd',
            'server_public_key_pin' => 'sha256//'.base64_encode(str_repeat('p', 32)),
            'is_active' => true,
        ]);

        $this->vm = VirtualMachine::create([
            'user_id' => $this->user->id,
            'host_id' => $this->incusHost->id,
            'name' => 'vm-snap-01',
            'incus_project' => 'tenant-snap-proj',
            'instance_type' => 'virtual-machine',
            'status' => 'running',
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'images:ubuntu/24.04',
        ]);
    }

    protected function tearDown(): void
    {
        HypervisorManager::resetFake();
        parent::tearDown();
    }

    public function test_fake_driver_supports_create_list_restore_and_delete_snapshots(): void
    {
        $driver = new FakeHypervisorDriver;

        $this->assertSame([], $driver->listSnapshots($this->vm));

        // Set initial state and marker before taking first snapshot
        $driver->statuses[$this->vm->name] = 'Running';
        $driver->setStateMarker($this->vm, 'clean-v1');

        $this->assertTrue($driver->createSnapshot($this->vm, 'snap-1', false));
        // Duplicate snapshot name must fail
        $this->assertFalse($driver->createSnapshot($this->vm, 'snap-1', false));

        // Mutate state and take a second stateful snapshot
        $driver->statuses[$this->vm->name] = 'Stopped';
        $driver->setStateMarker($this->vm, 'dirty-v2');
        $this->assertTrue($driver->createSnapshot($this->vm, 'snap-2', true));

        $snapshots = $driver->listSnapshots($this->vm);
        $this->assertCount(2, $snapshots);

        $this->assertSame('snap-1', $snapshots[0]['name']);
        $this->assertNotEmpty($snapshots[0]['created_at']);
        $this->assertFalse($snapshots[0]['stateful']);
        $this->assertArrayHasKey('size', $snapshots[0]);
        $this->assertNull($snapshots[0]['size']);

        $this->assertSame('snap-2', $snapshots[1]['name']);
        $this->assertNotEmpty($snapshots[1]['created_at']);
        $this->assertTrue($snapshots[1]['stateful']);
        $this->assertArrayHasKey('size', $snapshots[1]);
        $this->assertNull($snapshots[1]['size']);

        // Restoring snap-1 should reset marker and status to snap-1's captured state and record restored snapshot
        $this->assertTrue($driver->restoreSnapshot($this->vm, 'snap-1'));
        $this->assertSame('snap-1', $driver->getRestoredSnapshot($this->vm));
        $this->assertSame('clean-v1', $driver->getStateMarker($this->vm));
        $this->assertSame('Running', $driver->getInstanceStatus($this->vm));
        $this->assertSame('snap-1', $driver->getInstanceState($this->vm)['restored_snapshot'] ?? null);

        // Restoring a non-existent snapshot must fail
        $this->assertFalse($driver->restoreSnapshot($this->vm, 'missing-snap'));

        // Deleting an existing snapshot succeeds and removes it from list
        $this->assertTrue($driver->deleteSnapshot($this->vm, 'snap-1'));
        $remaining = $driver->listSnapshots($this->vm);
        $this->assertCount(1, $remaining);
        $this->assertSame('snap-2', $remaining[0]['name']);

        // Deleting a missing or already-deleted snapshot must fail consistently with real driver
        $this->assertFalse($driver->deleteSnapshot($this->vm, 'snap-1'));
        $this->assertFalse($driver->deleteSnapshot($this->vm, 'never-existed'));
    }

    public function test_fake_driver_respects_set_fail_action_for_snapshot_operations(): void
    {
        $driver = new FakeHypervisorDriver;

        $driver->setFailAction('createSnapshot', true);
        $this->assertFalse($driver->createSnapshot($this->vm, 'snap-a'));

        $driver->setFailAction('createSnapshot', false);
        $this->assertTrue($driver->createSnapshot($this->vm, 'snap-a'));

        $driver->setFailAction('listSnapshots', true);
        $this->assertSame([], $driver->listSnapshots($this->vm));
        $driver->setFailAction('listSnapshots', false);
        $this->assertCount(1, $driver->listSnapshots($this->vm));

        $driver->setFailAction('restoreSnapshot', true);
        $this->assertFalse($driver->restoreSnapshot($this->vm, 'snap-a'));

        $driver->setFailAction('deleteSnapshot', true);
        $this->assertFalse($driver->deleteSnapshot($this->vm, 'snap-a'));
    }

    public function test_incus_driver_create_snapshot_sends_request_and_waits_for_async_operation(): void
    {
        Http::fake([
            'https://incus-snap.local:8443/1.0/instances/vm-snap-01/snapshots?project=tenant-snap-proj' => Http::response([
                'type' => 'async',
                'status' => 'Operation created',
                'status_code' => 100,
                'operation' => '/1.0/operations/op-create-snap-123',
            ], 202),
            'https://incus-snap.local:8443/1.0/operations/op-create-snap-123/wait*' => Http::response([
                'type' => 'sync',
                'status' => 'Success',
                'status_code' => 200,
                'metadata' => [
                    'status' => 'Success',
                    'status_code' => 200,
                ],
            ], 200),
        ]);

        $driver = new IncusDriver($this->incusHost);
        $result = $driver->createSnapshot($this->vm, 'daily-backup', true);

        $this->assertTrue($result);

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'POST') {
                return false;
            }

            /** @var array<string, mixed> $data */
            $data = $request->data();

            return $request->url() === 'https://incus-snap.local:8443/1.0/instances/vm-snap-01/snapshots?project=tenant-snap-proj'
                && ($data['name'] ?? null) === 'daily-backup'
                && ($data['stateful'] ?? null) === true;
        });

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'GET'
                && str_contains($request->url(), '/1.0/operations/op-create-snap-123/wait')
                && str_contains($request->url(), 'project=tenant-snap-proj');
        });
    }

    public function test_lxd_driver_list_snapshots_normalizes_response_items(): void
    {
        $this->vm->update(['host_id' => $this->lxdHost->id]);

        Http::fake([
            'https://lxd-snap.local:8443/1.0/instances/vm-snap-01/snapshots*' => Http::response([
                'type' => 'sync',
                'status' => 'Success',
                'status_code' => 200,
                'metadata' => [
                    [
                        'name' => 'vm-snap-01/snap-alpha',
                        'created_at' => '2026-11-02T10:00:00Z',
                        'stateful' => false,
                        'size' => 10485760,
                    ],
                    [
                        'name' => 'snap-beta',
                        'created_at' => '2026-11-02T11:30:00Z',
                        'stateful' => true,
                        'size' => -1,
                    ],
                    [
                        'name' => 'snap-gamma',
                        'created_at' => '2026-11-02T12:00:00Z',
                        'stateful' => false,
                    ],
                ],
            ], 200),
        ]);

        $driver = new LxdDriver($this->lxdHost);
        $snapshots = $driver->listSnapshots($this->vm);

        $this->assertSame([
            [
                'name' => 'snap-alpha',
                'created_at' => '2026-11-02T10:00:00Z',
                'stateful' => false,
                'size' => 10485760,
            ],
            [
                'name' => 'snap-beta',
                'created_at' => '2026-11-02T11:30:00Z',
                'stateful' => true,
                'size' => null,
            ],
            [
                'name' => 'snap-gamma',
                'created_at' => '2026-11-02T12:00:00Z',
                'stateful' => false,
                'size' => null,
            ],
        ], $snapshots);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'GET'
                && str_contains($request->url(), '/1.0/instances/vm-snap-01/snapshots')
                && str_contains($request->url(), 'project=tenant-snap-proj')
                && str_contains($request->url(), 'recursion=1');
        });
    }

    public function test_incus_driver_restore_snapshot_sends_put_with_restore_payload_and_waits(): void
    {
        Http::fake([
            'https://incus-snap.local:8443/1.0/instances/vm-snap-01?project=tenant-snap-proj' => Http::response([
                'type' => 'async',
                'status' => 'Operation created',
                'status_code' => 100,
                'operation' => '/1.0/operations/op-restore-456',
            ], 202),
            'https://incus-snap.local:8443/1.0/operations/op-restore-456/wait*' => Http::response([
                'type' => 'sync',
                'status' => 'Success',
                'status_code' => 200,
                'metadata' => [
                    'status' => 'Success',
                ],
            ], 200),
        ]);

        $driver = new IncusDriver($this->incusHost);
        $result = $driver->restoreSnapshot($this->vm, 'snap-alpha');

        $this->assertTrue($result);

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'PUT') {
                return false;
            }

            /** @var array<string, mixed> $data */
            $data = $request->data();

            return $request->url() === 'https://incus-snap.local:8443/1.0/instances/vm-snap-01?project=tenant-snap-proj'
                && ($data['restore'] ?? null) === 'snap-alpha';
        });
    }

    public function test_incus_driver_delete_snapshot_succeeds_and_fails_on_missing_snapshot(): void
    {
        Http::fake([
            'https://incus-snap.local:8443/1.0/instances/vm-snap-01/snapshots/snap-exists?project=tenant-snap-proj' => Http::response([
                'type' => 'async',
                'operation' => '/1.0/operations/op-del-789',
            ], 202),
            'https://incus-snap.local:8443/1.0/operations/op-del-789/wait*' => Http::response([
                'type' => 'sync',
                'metadata' => ['status' => 'Success'],
            ], 200),
            'https://incus-snap.local:8443/1.0/instances/vm-snap-01/snapshots/snap-missing?project=tenant-snap-proj' => Http::response([
                'type' => 'error',
                'error' => 'Instance snapshot not found',
                'error_code' => 404,
            ], 404),
        ]);

        $driver = new IncusDriver($this->incusHost);

        $this->assertTrue($driver->deleteSnapshot($this->vm, 'snap-exists'));
        $this->assertFalse($driver->deleteSnapshot($this->vm, 'snap-missing'));
    }

    public function test_gateway_delegates_snapshot_methods_and_propagates_hypervisor_fingerprint_mismatch_exception(): void
    {
        $fakeDriver = new FakeHypervisorDriver;
        HypervisorManager::fake($fakeDriver);

        $gateway = app(HypervisorGateway::class);

        $this->assertTrue($gateway->createSnapshot($this->vm, 'gw-snap', false));
        $list = $gateway->listSnapshots($this->vm);
        $this->assertCount(1, $list);
        $this->assertSame('gw-snap', $list[0]['name']);

        $this->assertTrue($gateway->restoreSnapshot($this->vm, 'gw-snap'));
        $this->assertSame('gw-snap', $fakeDriver->getRestoredSnapshot($this->vm));

        $this->assertTrue($gateway->deleteSnapshot($this->vm, 'gw-snap'));
        $this->assertFalse($gateway->deleteSnapshot($this->vm, 'gw-snap'));

        // Now test real driver with fingerprint mismatch mapped to App\Services\Hypervisor\Exceptions\FingerprintMismatchException
        HypervisorManager::resetFake();

        $this->incusHost->update([
            'server_fingerprint' => str_repeat('a', 64),
            'server_public_key_pin' => 'sha256//'.base64_encode(str_repeat('a', 32)),
        ]);

        $mockGateway = Mockery::mock(HypervisorGateway::class);
        $mockGateway->shouldReceive('client')
            ->once()
            ->andThrow(new FingerprintMismatchException('', '', 'Pin mismatch'));

        $driver = new IncusDriver($this->incusHost, gateway: $mockGateway);

        $this->expectException(FingerprintMismatchException::class);
        $driver->createSnapshot($this->vm, 'should-fail-pinning');
    }
}
