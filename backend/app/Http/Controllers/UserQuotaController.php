<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\UserQuotaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\RouteAttributes\Attributes\Get;

/**
 * Controller providing user quota and live resource consumption metrics.
 */
final class UserQuotaController extends Controller
{
    public function __construct(
        private readonly UserQuotaService $quotaService,
    ) {}

    /**
     * Show resource quotas and current allocation metrics for the authenticated user,
     * or for a targeted tenant when queried by an administrator.
     */
    #[Get('quota', name: 'quota.show', middleware: ['auth:sanctum', 'active'])]
    public function show(Request $request): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $request->user();
        $targetUser = $currentUser;

        if ($request->has('user_id') && $currentUser->hasRole('admin')) {
            $userId = $request->integer('user_id');
            /** @var User|null $foundUser */
            $foundUser = User::find($userId);
            if ($foundUser !== null) {
                $targetUser = $foundUser;
            }
        }

        /** @var array{host_id?: int|null, status?: string|null, search?: string|null, vm_id?: int|null} $filters */
        $filters = [
            'host_id' => $request->filled('host_id') ? $request->integer('host_id') : null,
            'status' => $request->filled('status') ? $request->string('status')->value() : null,
            'search' => $request->filled('search') ? $request->string('search')->value() : null,
            'vm_id' => $request->filled('vm_id') ? $request->integer('vm_id') : null,
        ];

        $metrics = $this->quotaService->getMetrics($targetUser, $filters);

        return response()->json($metrics);
    }
}
