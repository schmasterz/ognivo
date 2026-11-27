<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vps\ResolvePlanChangeRequest;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Models\VpsPlan;
use App\Models\VpsPlanChange;
use App\Models\VpsPlanVersion;
use App\Services\Vps\VpsPlanChangeService;
use App\States\VpsPlanChange\VpsPlanChangeState;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Middleware;
use Spatie\RouteAttributes\Attributes\Post;
use Spatie\RouteAttributes\Attributes\Prefix;

/**
 * Controller managing administrative inspection and manual reconciliation of VPS plan-change operations.
 */
#[Group('Admin VPS Plan Changes')]
#[Prefix('admin/vps/plan-changes')]
#[Middleware(['auth:sanctum', 'active', 'can:vps.plan_change.manage'])]
final class AdminVpsPlanChangeController extends Controller
{
    public function __construct(
        private readonly VpsPlanChangeService $planChangeService,
    ) {}

    /**
     * List VPS plan-change operations with optional status, user, and VM filters.
     */
    #[Get('', name: 'admin.vps.plan-changes.index')]
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', 'string', Rule::in(['all', ...VpsPlanChangeState::values()])],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'vm_id' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = VpsPlanChange::query()
            ->with([
                'virtualMachine:id,name,incus_project,status,user_id',
                'user:id,name,email',
                'initiator:id,name,email',
                'fromVersion.vpsPlan:id,name,slug',
                'toVersion.vpsPlan:id,name,slug',
            ])
            ->latest('id');

        if ($request->filled('status')) {
            $status = $request->string('status')->value();
            if ($status !== 'all') {
                $query->where('status', $status);
            }
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        if ($request->filled('vm_id')) {
            $query->where('virtual_machine_id', $request->integer('vm_id'));
        }

        $perPage = min(max($request->integer('per_page', 20), 1), 100);
        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => array_map(
                fn (VpsPlanChange $change): array => $this->serializePlanChange($change),
                $paginator->items(),
            ),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Show administrative details for a single VPS plan-change operation by public ULID.
     */
    #[Get('{public_id}', name: 'admin.vps.plan-changes.show')]
    public function show(string $public_id): JsonResponse
    {
        $change = VpsPlanChange::query()
            ->with([
                'virtualMachine:id,name,incus_project,status,user_id',
                'user:id,name,email',
                'initiator:id,name,email',
                'fromVersion.vpsPlan:id,name,slug',
                'toVersion.vpsPlan:id,name,slug',
            ])
            ->where('public_id', $public_id)
            ->first();

        if ($change === null) {
            abort(404, 'Plan change not found.');
        }

        return response()->json([
            'data' => $this->serializePlanChange($change),
        ]);
    }

    /**
     * Manually resolve a plan-change operation stuck in reconciliation_required.
     */
    #[Post('{public_id}/resolve', name: 'admin.vps.plan-changes.resolve')]
    public function resolve(ResolvePlanChangeRequest $request, string $public_id): JsonResponse
    {
        $change = VpsPlanChange::query()
            ->where('public_id', $public_id)
            ->first();

        if ($change === null) {
            abort(404, 'Plan change not found.');
        }

        /** @var User $admin */
        $admin = $request->user();
        $action = $request->string('action')->value();
        $note = $request->filled('note') ? $request->string('note')->value() : null;

        try {
            $resolved = $this->planChangeService->resolve($change, $action, $note, $admin);
        } catch (ValidationException $e) {
            if (array_key_exists('hypervisor', $e->errors())) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'code' => 'HOST_UNREACHABLE',
                    'errors' => $e->errors(),
                ], 503);
            }

            throw $e;
        }

        $resolved->load([
            'virtualMachine:id,name,incus_project,status,user_id',
            'user:id,name,email',
            'initiator:id,name,email',
            'fromVersion.vpsPlan:id,name,slug',
            'toVersion.vpsPlan:id,name,slug',
        ]);

        return response()->json([
            'data' => $this->serializePlanChange($resolved),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePlanChange(VpsPlanChange $change): array
    {
        /** @var VirtualMachine|null $vm */
        $vm = $change->relationLoaded('virtualMachine') ? $change->getRelation('virtualMachine') : null;
        /** @var User|null $user */
        $user = $change->relationLoaded('user') ? $change->getRelation('user') : null;
        /** @var User|null $initiator */
        $initiator = $change->relationLoaded('initiator') ? $change->getRelation('initiator') : null;
        /** @var VpsPlanVersion|null $fromVersion */
        $fromVersion = $change->relationLoaded('fromVersion') ? $change->getRelation('fromVersion') : null;
        /** @var VpsPlanVersion|null $toVersion */
        $toVersion = $change->relationLoaded('toVersion') ? $change->getRelation('toVersion') : null;

        return [
            'id' => $change->id,
            'public_id' => $change->public_id,
            'virtual_machine_id' => $change->virtual_machine_id,
            'user_id' => $change->user_id,
            'initiated_by' => $change->initiated_by,
            'from_version_id' => $change->from_version_id,
            'to_version_id' => $change->to_version_id,
            'old_resources' => $change->old_resources,
            'new_resources' => $change->new_resources,
            'status' => $change->status->getValue(),
            'stage' => $change->stage,
            'authorization_ref' => $change->authorization_ref,
            'driver_name' => $change->driver_name,
            'driver_operation_id' => $change->driver_operation_id,
            'error_code' => $change->error_code,
            'error_message' => $change->error_message,
            'requested_at' => $change->requested_at?->toISOString(),
            'started_at' => $change->started_at?->toISOString(),
            'completed_at' => $change->completed_at?->toISOString(),
            'failed_at' => $change->failed_at?->toISOString(),
            'apply_deadline' => $change->apply_deadline?->toISOString(),
            'created_at' => $change->created_at?->toISOString(),
            'updated_at' => $change->updated_at?->toISOString(),
            'virtual_machine' => $vm !== null ? [
                'id' => $vm->id,
                'name' => $vm->name,
                'incus_project' => $vm->incus_project,
                'status' => $vm->status->getValue(),
                'user_id' => $vm->user_id,
            ] : null,
            'user' => $user !== null ? [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ] : null,
            'initiator' => $initiator !== null ? [
                'id' => $initiator->id,
                'name' => $initiator->name,
                'email' => $initiator->email,
            ] : null,
            'from_version' => $this->serializeVersionSummary($fromVersion),
            'to_version' => $this->serializeVersionSummary($toVersion),
        ];
    }

    /**
     * @return array{id: int, vps_plan_id: int, version: int|null, plan_name: string|null, plan_slug: string|null}|null
     */
    private function serializeVersionSummary(?VpsPlanVersion $version): ?array
    {
        if ($version === null) {
            return null;
        }

        /** @var VpsPlan|null $plan */
        $plan = $version->relationLoaded('vpsPlan') ? $version->getRelation('vpsPlan') : null;

        return [
            'id' => $version->id,
            'vps_plan_id' => $version->vps_plan_id,
            'version' => $version->version,
            'plan_name' => $plan?->name,
            'plan_slug' => $plan?->slug,
        ];
    }
}
