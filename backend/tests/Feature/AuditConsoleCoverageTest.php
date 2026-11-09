<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\AuditContext;
use App\Models\Host;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\VmLifecycleService;
use App\States\Vm\Running;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class AuditConsoleCoverageTest extends TestCase
{
    use AuditAssertionTrait;
    use RefreshDatabase;

    private User $owner;

    private User $intruder;

    private Host $host;

    private VirtualMachine $vm;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $this->owner = User::factory()->create(['status' => 'active']);
        $this->owner->assignRole('customer');

        $this->intruder = User::factory()->create(['status' => 'active']);
        $this->intruder->assignRole('customer');

        $this->host = Host::create([
            'name' => 'console-test-host-'.uniqid(),
            'endpoint_url' => 'https://127.0.0.1:8443',
            'driver' => 'fake',
            'status' => 'ok',
            'is_active' => true,
        ]);

        $this->vm = VirtualMachine::create([
            'user_id' => $this->owner->id,
            'host_id' => $this->host->id,
            'name' => 'console-vm-'.uniqid(),
            'incus_project' => 'user-'.$this->owner->id,
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'images:ubuntu/24.04',
        ]);

        app(AuditContext::class)->setRequestId('req-console-test-5555');
    }

    public function test_console_ticket_issued_audited_without_ticket_value(): void
    {
        Redis::spy();

        $service = app(VmLifecycleService::class);

        $ticket = $service->issueConsoleTicket($this->vm, $this->owner);
        $this->assertNotEmpty($ticket);

        $row = $this->assertAuditRow(
            action: 'console.ticket.issued',
            outcome: 'success',
            tenantUserId: $this->owner->id,
            visibility: 'tenant',
            requestId: 'req-console-test-5555',
        );

        $context = json_decode((string) $row->context, true);
        $this->assertSame($this->vm->id, $context['virtual_machine_id']);
        $this->assertArrayNotHasKey('ticket', $context);
        $this->assertStringNotContainsString($ticket, (string) $row->context);
    }

    public function test_denied_console_ticket_attempt_audited_as_vm_action_denied(): void
    {
        $service = app(VmLifecycleService::class);

        try {
            $service->issueConsoleTicket($this->vm, $this->intruder);
            $this->fail('Expected 403 HttpException for unauthorized console access');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $row = $this->assertAuditRow(
            action: 'vm.action.denied',
            outcome: 'denied',
            tenantUserId: $this->owner->id,
            visibility: 'tenant',
            requestId: 'req-console-test-5555',
        );

        $context = json_decode((string) $row->context, true);
        $this->assertSame('console.ticket', $context['action']);
        $this->assertSame('policy_denial', $context['reason']);
    }
}
