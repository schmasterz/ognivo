<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Audit\AuditAction;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Audit\AuditOutcome;
use App\Http\Controllers\Controller;
use App\Http\Requests\Vm\CreateVmRequest;
use App\Http\Requests\Vm\VmActionRequest;
use App\Http\Resources\VirtualMachineResource;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\ConsoleTicketService;
use App\Services\HostCapacityGuard;
use App\Services\Hypervisor\HypervisorManager;
use App\Services\VmLifecycleService;
use App\Services\VmProvisioningService;
use App\Support\Cast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Spatie\RouteAttributes\Attributes\Delete;
use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Middleware;
use Spatie\RouteAttributes\Attributes\Post;
use Spatie\RouteAttributes\Attributes\Prefix;

/**
 * Controller managing Virtual Machine lifecycle operations, actions, and console sessions.
 */
#[Prefix('vms')]
#[Middleware(['auth:sanctum', 'active'])]
final class VirtualMachineController extends Controller
{
    public function __construct(
        private readonly VmProvisioningService $provisioningService,
        private readonly HypervisorManager $hypervisorManager,
        private readonly VmLifecycleService $lifecycleService,
        private ?ConsoleTicketService $consoleTicketService = null,
        private readonly ?AuditLogger $auditLogger = null,
    ) {
        $this->consoleTicketService = $consoleTicketService ?? app(ConsoleTicketService::class);
    }

    private function ticketService(): ConsoleTicketService
    {
        return $this->consoleTicketService ??= app(ConsoleTicketService::class);
    }

    private function logger(): AuditLogger
    {
        return $this->auditLogger ?? app(AuditLogger::class);
    }

    /**
     * List virtual machines for the authenticated user (or all with filters if admin).
     */
    #[Get('', name: 'vms.index')]
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $query = VirtualMachine::with(['host', 'primaryAllocation.pool', 'user:id,name,email', 'image']);

        if (! $user->hasRole('admin')) {
            $query->where('user_id', $user->id);
        } else {
            if ($request->filled('user_id')) {
                $query->where('user_id', $request->integer('user_id'));
            }
        }

        if ($request->filled('host_id')) {
            $query->where('host_id', $request->integer('host_id'));
        }

        if ($request->filled('status')) {
            $status = $request->string('status')->value();
            if ($status === 'deleted') {
                $query->where('status', 'deleted');
            } elseif (in_array($status, ['active', 'inactive', 'pending'], true)) {
                $query->whereHas('user', fn (Builder $uq): Builder => $uq->where('status', $status))
                    ->where('status', '!=', 'deleted');
            } else {
                $query->where('status', $status);
            }
        } else {
            $query->where('status', '!=', 'deleted');
        }

        if ($request->filled('instance_type')) {
            $query->where('instance_type', $request->string('instance_type')->value());
        }

        if ($request->filled('search')) {
            $search = $request->string('search')->value();
            $query->where(function (Builder $q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('incus_project', 'like', "%{$search}%")
                    ->orWhere('ipv4_address', 'like', "%{$search}%")
                    ->orWhereHas('user', function (Builder $uq) use ($search): void {
                        $uq->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        $perPage = max(1, min(100, $request->integer('per_page', 10)));
        $vms = $query->latest()->paginate($perPage);

        if ($user->hasRole('admin')) {
            /** @var list<int> $hostIds */
            $hostIds = $vms->getCollection()
                ->pluck('host_id')
                ->filter()
                ->unique()
                ->map(fn (mixed $id): int => Cast::int($id))
                ->values()
                ->all();

            if ($hostIds !== []) {
                $guard = app(HostCapacityGuard::class);
                $usages = $guard->getCommittedUsageForHostIds($hostIds);

                foreach ($vms->getCollection() as $vm) {
                    if ($vm->relationLoaded('host') && $vm->host !== null) {
                        $hostId = Cast::int($vm->host->id);
                        $vm->host->setAttribute(
                            'precomputed_committed_usage',
                            $usages[$hostId] ?? ['cpu' => 0, 'ram' => 0, 'disk' => 0]
                        );
                    }
                }
            }
        }

        return VirtualMachineResource::collection($vms)->additional([
            'current_page' => $vms->currentPage(),
            'last_page' => $vms->lastPage(),
            'per_page' => $vms->perPage(),
            'total' => $vms->total(),
        ]);
    }

    /**
     * Provision a new virtual machine or container under quota constraints.
     */
    #[Post('', name: 'vms.store', middleware: ['throttle:vm-actions', 'idempotency'])]
    public function store(CreateVmRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $dto = $request->toDTO();
        $vm = $this->provisioningService->provision($user, $dto->toArray());

        return response()->json([
            'message' => __('messages.vm.provisioning_initiated'),
            'virtual_machine' => new VirtualMachineResource($vm),
        ], 202);
    }

    /**
     * Retrieve details for a specific virtual machine.
     */
    #[Get('{virtualMachine}', name: 'vms.show')]
    public function show(Request $request, VirtualMachine $virtualMachine): JsonResponse
    {
        Gate::authorize('view', $virtualMachine);

        $virtualMachine->load(['host', 'primaryAllocation.pool', 'user']);

        return response()->json(new VirtualMachineResource($virtualMachine));
    }

    /**
     * Dispatch lifecycle actions (start, stop, restart, delete).
     */
    #[Post('{virtualMachine}/action', name: 'vms.action', middleware: ['throttle:vm-actions'])]
    public function action(VmActionRequest $request, VirtualMachine $virtualMachine): JsonResponse
    {
        $dto = $request->toDTO();
        /** @var User|null $user */
        $user = $request->user();

        $this->lifecycleService->handleActionRequest(
            $virtualMachine,
            $dto->action,
            $dto->force,
            $user,
            $dto->osImage,
            $dto->cloudInit,
            $dto,
        );

        return response()->json([
            'message' => __('messages.vm.action_executed', ['action' => $dto->action]),
            'virtual_machine' => new VirtualMachineResource($virtualMachine->fresh() ?? $virtualMachine),
        ]);
    }

    /**
     * Retrieve the text boot log buffer from the hypervisor.
     */
    #[Get('{virtualMachine}/console-log', name: 'vms.console_log', middleware: ['throttle:6,1'])]
    public function consoleLog(Request $request, VirtualMachine $virtualMachine): JsonResponse
    {
        Gate::authorize('viewConsole', $virtualMachine);

        $driver = $this->hypervisorManager->driverForVm($virtualMachine);
        $output = $driver->getInstanceConsoleLog($virtualMachine);

        // Enforce 50 KB cap
        if (strlen($output) > 51200) {
            $output = substr($output, -51200);
        }

        $userId = (int) ($request->user()?->id ?? 0);
        $bytesReturned = strlen($output);

        Log::info('console.log.viewed', [
            'user_id' => $userId,
            'virtual_machine_id' => $virtualMachine->id,
            'bytes_returned' => $bytesReturned,
        ]);

        $this->logger()->record(new AuditEntry(
            action: AuditAction::VmActionRequested,
            outcome: AuditOutcome::Success,
            subject: $virtualMachine,
            tenantUserId: $virtualMachine->user_id,
            visibility: 'tenant',
            context: [
                'action' => 'console_log.viewed',
                'virtual_machine_id' => $virtualMachine->id,
                'bytes_returned' => $bytesReturned,
            ],
        ));

        return response()->json([
            'virtual_machine_id' => $virtualMachine->id,
            'name' => $virtualMachine->name,
            'output' => $output,
        ]);
    }

    /**
     * Fallback for GET request on console route to return 404 Not Found.
     */
    #[Get('{virtualMachine}/console', name: 'vms.console_get')]
    public function consoleGet(): never
    {
        abort(404);
    }

    /**
     * Issue an interactive console session and ticket for the virtual machine.
     */
    #[Post('{virtualMachine}/console', name: 'vms.console', middleware: ['throttle:6,1'])]
    public function console(Request $request, VirtualMachine $virtualMachine): JsonResponse
    {
        if (! $request->hasSession()) {
            return response()->json(['message' => 'Session required.'], 401);
        }

        $loginAt = $request->session()->get('login_at');
        if (! is_int($loginAt) && ! is_numeric($loginAt)) {
            return response()->json(['message' => 'Session required.'], 401);
        }

        $rawType = $request->input('type');
        $type = is_string($rawType) && $rawType !== '' ? $rawType : 'console';
        if (! in_array($type, ['console', 'vga'], true)) {
            return response()->json(['message' => 'Invalid console type. Supported types: console, vga.'], 422);
        }

        if ($virtualMachine->instance_type === 'container' && $type === 'vga') {
            return response()->json(['message' => 'Containers do not support graphical VGA console.'], 422);
        }

        $rawDriver = $request->input('driver');
        $driver = is_string($rawDriver) && $rawDriver !== '' ? $rawDriver : null;
        if ($driver !== null && ! in_array($driver, ['auto', 'exec', 'console'], true)) {
            return response()->json(['message' => 'Invalid driver. Supported drivers: auto, exec, console.'], 422);
        }

        $cols = Cast::int($request->input('cols') ?? $request->input('width'), 80);
        $rows = Cast::int($request->input('rows') ?? $request->input('height'), 24);

        $sessionExpiresAt = (int) $loginAt + (max(1, Cast::int(config('session.absolute_lifetime', 1440), 1440)) * 60);

        $session = $this->ticketService()->issueTicket(
            $virtualMachine,
            $request->user(),
            $type,
            $cols,
            $rows,
            $driver,
            $sessionExpiresAt,
        );

        return response()->json($session->toArray());
    }

    /**
     * Close an active interactive console or exec terminal session.
     */
    #[Delete('{virtualMachine}/console/{sessionId}', name: 'vms.console.close')]
    public function closeConsole(Request $request, VirtualMachine $virtualMachine, string $sessionId): JsonResponse
    {
        Gate::authorize('viewConsole', $virtualMachine);

        $rawReason = $request->input('reason');
        $reason = is_string($rawReason) && $rawReason !== '' ? $rawReason : 'client_disconnect';

        $closed = $this->ticketService()->closeSession(
            $virtualMachine,
            $sessionId,
            $request->user(),
            $reason
        );

        return response()->json(['closed' => $closed]);
    }
}
