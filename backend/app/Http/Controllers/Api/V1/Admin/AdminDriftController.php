<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\DriftReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Middleware;
use Spatie\RouteAttributes\Attributes\Post;
use Spatie\RouteAttributes\Attributes\Prefix;

/**
 * Controller managing infrastructure state drift reports for administrators.
 */
#[Prefix('admin')]
#[Middleware(['auth:sanctum', 'active', 'admin'])]
final class AdminDriftController extends Controller
{
    /**
     * List state drift reports with optional filters.
     */
    #[Get('drift', name: 'admin.drift')]
    public function index(Request $request): JsonResponse
    {
        $query = DriftReport::query()
            ->with(['host:id,name,endpoint_url', 'virtualMachine:id,name,status,user_id'])
            ->latest('id');

        if ($request->filled('host_id')) {
            $query->where('host_id', $request->integer('host_id'));
        }

        if ($request->filled('kind')) {
            $query->where('kind', $request->string('kind')->value());
        }

        $status = $request->input('status', 'unresolved');
        if ($status === 'unresolved') {
            $query->whereNull('resolved_at');
        } elseif ($status === 'resolved') {
            $query->whereNotNull('resolved_at');
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $paginated = $query->paginate($perPage);

        $unresolvedCount = DriftReport::query()->whereNull('resolved_at')->count();

        return response()->json([
            'data' => $paginated->items(),
            'meta' => [
                'unresolved_count' => $unresolvedCount,
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * Retrieve notification badge counter for unresolved drifts.
     */
    #[Get('drift/badge', name: 'admin.drift.badge')]
    public function badge(): JsonResponse
    {
        $unresolvedCount = DriftReport::query()->whereNull('resolved_at')->count();

        return response()->json([
            'count' => $unresolvedCount,
        ]);
    }

    /**
     * Manually mark a drift report as resolved.
     */
    #[Post('drift/{id}/resolve', name: 'admin.drift.resolve')]
    public function resolve(int $id): JsonResponse
    {
        /** @var DriftReport $drift */
        $drift = DriftReport::query()->findOrFail($id);

        if ($drift->resolved_at === null) {
            $drift->resolved_at = now();
            $drift->save();
        }

        return response()->json([
            'message' => 'Drift report marked as resolved.',
            'drift' => $drift,
        ]);
    }
}
