<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Audit\AuditAction;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Audit\AuditOutcome;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignQuotaGroupRequest;
use App\Http\Requests\Admin\CreateUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\AdminUserResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\UserService;
use App\States\User\Active;
use App\States\User\Deleting;
use App\States\User\UserState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Spatie\RouteAttributes\Attributes\Delete;
use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Middleware;
use Spatie\RouteAttributes\Attributes\Post;
use Spatie\RouteAttributes\Attributes\Prefix;
use Spatie\RouteAttributes\Attributes\Put;

/**
 * Controller managing administrative operations on tenant user accounts and role assignments.
 */
#[Prefix('admin/users')]
#[Middleware(['auth:sanctum', 'active', 'admin'])]
final class AdminUserController extends Controller
{
    public function __construct(
        private readonly UserService $userService,
        private readonly ?AuditLogger $auditLogger = null,
    ) {}

    private function logger(): AuditLogger
    {
        return $this->auditLogger ?? app(AuditLogger::class);
    }

    /**
     * List all registered tenant users with their workload, IP counts, and resource quotas.
     */
    #[Get('', name: 'admin.users.index')]
    public function index(Request $request): JsonResponse
    {
        $filters = [
            'user_id' => $request->filled('user_id') ? $request->integer('user_id') : null,
            'host_id' => $request->filled('host_id') ? $request->integer('host_id') : null,
            'status' => $request->filled('status') ? $request->string('status')->value() : null,
            'search' => $request->filled('search') ? $request->string('search')->value() : null,
        ];

        $perPage = max(1, min(100, $request->integer('per_page', 10)));
        $paginator = $this->userService->paginateAll($filters, $perPage);

        return response()->json([
            'data' => AdminUserResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ]);
    }

    /**
     * Lightweight search endpoint for user autocompletion in filters and selectors.
     */
    #[Get('search', name: 'admin.users.search')]
    public function search(Request $request): JsonResponse
    {
        $query = $request->string('query')->trim()->value();
        $limit = max(1, min(50, $request->integer('limit', 10)));

        $users = User::query()
            ->select(['id', 'name', 'email'])
            ->when($query !== '', function (Builder $q) use ($query): void {
                $q->where(function (Builder $sub) use ($query): void {
                    $sub->where('email', 'like', "%{$query}%")
                        ->orWhere('name', 'like', "%{$query}%");
                });
            })
            ->orderBy('email')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => $users,
        ]);
    }

    /**
     * Create a new user account with designated role, assigned quota group, and custom boundaries.
     */
    #[Post('', name: 'admin.users.store')]
    public function store(CreateUserRequest $request): JsonResponse
    {
        $user = $this->userService->create($request->toDTO());

        return response()->json([
            'message' => __('messages.admin.user_created'),
            'user' => new UserResource($user),
        ], 201);
    }

    /**
     * Update an existing user account, roles, status, and quota boundaries.
     */
    #[Put('{user}', name: 'admin.users.update')]
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $dto = $request->toDTO();

        if ($admin->id === $user->id && $dto->status !== null && $dto->status !== Active::$name) {
            $msg = __('messages.admin.cannot_suspend_self');
            abort(422, is_string($msg) ? $msg : 'Cannot suspend your own account.');
        }

        $updatedUser = $this->userService->update($user, $dto, $admin);

        return response()->json([
            'message' => __('messages.admin.user_updated'),
            'user' => new UserResource($updatedUser),
        ]);
    }

    /**
     * Update the status of a user account (active, suspended, inactive, pending) and suspend workloads if suspended.
     */
    #[Post('{user}/status', name: 'admin.users.status')]
    public function updateStatus(Request $request, User $user): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $request->validate([
            'status' => ['required', 'string', Rule::in(UserState::assignableValues())],
        ]);

        $status = $request->string('status')->value();
        $updatedUser = $this->userService->updateStatus($admin, $user, $status);

        return response()->json([
            'message' => __('messages.admin.user_updated'),
            'user' => new UserResource($updatedUser),
        ]);
    }

    /**
     * Display the specified tenant user account.
     */
    #[Get('{user}', name: 'admin.users.show')]
    public function show(User $user): JsonResponse
    {
        return response()->json([
            'user' => new AdminUserResource($user->load(['roles', 'quota', 'quotaGroup'])->loadCount(['virtualMachines', 'ipAllocations'])),
        ]);
    }

    /**
     * Delete a tenant user and release related infrastructure bindings.
     */
    #[Delete('{user}', name: 'admin.users.destroy')]
    public function destroy(Request $request, User $user): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $this->userService->delete($admin, $user);

        return response()->json([
            'message' => __('messages.admin.user_deletion_queued'),
            'status' => Deleting::$name,
        ], 202);
    }

    /**
     * Assign a user directly to a specific Quota Group and synchronize limits.
     */
    #[Post('{user}/assign-quota-group', name: 'admin.users.assign_quota_group')]
    public function assignQuotaGroup(AssignQuotaGroupRequest $request, User $user): JsonResponse
    {
        $dto = $request->toDTO();
        $group = $this->userService->assignQuotaGroup($user, $dto->quotaGroupId);

        return response()->json([
            'message' => __('messages.admin.quota_group_assigned', ['group' => $group->name]),
            'user' => new UserResource($user->fresh(['roles', 'quota', 'quotaGroup']) ?? $user),
        ], 200);
    }

    /**
     * Start a session-based impersonation for the specified tenant user (30-minute TTL) and record impersonation.started.
     */
    #[Post('{user}/impersonate', name: 'admin.users.impersonate')]
    public function impersonate(Request $request, User $user): JsonResponse
    {
        if (! $request->hasSession()) {
            abort(403, 'This endpoint is available to the browser session only.');
        }

        /** @var User $admin */
        $admin = $request->user();

        if ($admin->id === $user->id) {
            abort(403, 'Cannot impersonate yourself.');
        }

        if ($user->hasRole('admin')) {
            abort(403, 'Cannot impersonate another administrator.');
        }

        if (! $user->status->equals(Active::class)) {
            abort(403, 'Cannot impersonate an inactive user.');
        }

        $expiresAt = Carbon::now()->addMinutes(30);

        $this->logger()->record(new AuditEntry(
            action: AuditAction::ImpersonationStarted,
            outcome: AuditOutcome::Success,
            subject: $user,
            tenantUserId: $user->id,
            impersonatorId: $admin->id,
            visibility: 'tenant',
            context: [
                'impersonator_id' => $admin->id,
                'impersonator_email' => $admin->email,
                'target_user_id' => $user->id,
                'target_user_email' => $user->email,
            ],
            pii: [
                'admin_email' => $admin->email,
                'user_email' => $user->email,
            ],
        ));

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put([
            'impersonator_id' => $admin->id,
            'impersonation_expires_at' => $expiresAt->timestamp,
        ]);

        return response()->json([
            'user' => new UserResource($user),
            'expires_at' => $expiresAt->toISOString(),
            'message' => 'Impersonation session started successfully.',
        ]);
    }
}
