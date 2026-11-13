<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Audit\AuditContext;
use App\Models\Host;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\ConsoleTicketService;
use App\Services\Hypervisor\HypervisorManager;
use App\States\Vm\Running;
use App\States\Vm\Stopped;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class ConsoleTicketServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $otherUser;

    private User $admin;

    private Host $host;

    private VirtualMachine $vm;

    private ConsoleTicketService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $this->owner = User::factory()->create([
            'status' => 'active',
        ]);
        $this->owner->assignRole('customer');

        $this->otherUser = User::factory()->create([
            'status' => 'active',
        ]);
        $this->otherUser->assignRole('customer');

        $this->admin = User::factory()->create([
            'status' => 'active',
        ]);
        $this->admin->assignRole('admin');

        $this->host = Host::create([
            'name' => 'fake-console-host',
            'endpoint_url' => 'https://fake-node.local:8443',
            'driver' => 'fake',
            'is_active' => true,
        ]);

        $this->vm = VirtualMachine::create([
            'user_id' => $this->owner->id,
            'host_id' => $this->host->id,
            'name' => 'console-test-vm',
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'images:ubuntu/24.04',
        ]);

        $hypervisorManager = app(HypervisorManager::class);
        $this->service = new ConsoleTicketService($hypervisorManager);
    }

    public function test_issue_ticket_stores_payload_in_redis_and_returns_safe_dto(): void
    {
        Redis::spy();

        $dto = $this->service->issueTicket($this->vm, $this->owner, 'console');

        $this->assertSame($this->vm->id, $dto->virtualMachineId);
        $this->assertSame(64, strlen($dto->ticket));
        $this->assertSame('/console?ticket='.$dto->ticket, $dto->websocketPath);
        $this->assertNotEmpty($dto->expiresAt);
        $this->assertSame('console', $dto->type);

        Redis::shouldHaveReceived('setex')->with(
            'console:ticket:'.$dto->ticket,
            60,
            \Mockery::on(function (string $json): bool {
                /** @var array<string, mixed> $data */
                $data = json_decode($json, true);

                return $data['user_id'] === $this->owner->id
                    && $data['virtual_machine_id'] === $this->vm->id
                    && $data['console_type'] === 'console'
                    && str_contains($data['websocket_path'], 'fake-op-console-');
            })
        );
    }

    public function test_single_use_ticket_redemption(): void
    {
        $ticket = 'test-ticket-single-use-12345';
        $payload = [
            'user_id' => $this->owner->id,
            'virtual_machine_id' => $this->vm->id,
            'console_type' => 'console',
        ];

        Redis::shouldReceive('command')
            ->with('getdel', ['console:ticket:'.$ticket])
            ->once()
            ->andReturn(json_encode($payload));

        $result = $this->service->redeemTicket($ticket);
        $this->assertNotNull($result);
        $this->assertSame($this->owner->id, $result['user_id']);

        // Subsequent redemption returns null
        Redis::shouldReceive('command')
            ->with('getdel', ['console:ticket:'.$ticket])
            ->once()
            ->andReturn(null);

        $secondResult = $this->service->redeemTicket($ticket);
        $this->assertNull($secondResult);
    }

    public function test_redeem_ticket_rejects_invalid_ticket_format(): void
    {
        $this->assertNull($this->service->redeemTicket('*'));
        $this->assertNull($this->service->redeemTicket('?'));
        $this->assertNull($this->service->redeemTicket('short'));
        $this->assertNull($this->service->redeemTicket('invalid*glob?ticket-123456'));
    }

    public function test_issue_ticket_fails_if_vm_is_not_running(): void
    {
        $this->vm->status->transitionTo(Stopped::class);
        $this->vm->save();

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Interactive console is only available while the instance is running.');

        $this->service->issueTicket($this->vm, $this->owner);
    }

    public function test_issue_ticket_denies_admin_due_to_privacy_policy(): void
    {
        $this->expectException(HttpException::class);

        $this->service->issueTicket($this->vm, $this->admin);
    }

    public function test_issue_ticket_denies_other_user(): void
    {
        $this->expectException(HttpException::class);

        $this->service->issueTicket($this->vm, $this->otherUser);
    }

    public function test_issue_ticket_denies_active_impersonation(): void
    {
        $context = app(AuditContext::class);
        $context->setImpersonatorId($this->admin->id);

        try {
            $this->expectException(HttpException::class);
            $this->expectExceptionMessage('Issuing console tickets is forbidden while impersonating.');

            $this->service->issueTicket($this->vm, $this->owner);
        } finally {
            $context->setImpersonatorId(null);
        }
    }
}
