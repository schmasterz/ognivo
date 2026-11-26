<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vm\VmMetricsRequest;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\Metrics\VmMetricsAuditService;
use App\Services\Metrics\VmMetricsConfigService;
use App\Services\Metrics\VmMetricsQueryService;
use App\States\Vm\Deleted;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Middleware;
use Spatie\RouteAttributes\Attributes\Prefix;

/**
 * Controller serving historical per-instance telemetry series (FR-8, FR-10, FR-11).
 */
#[Group('Virtual Machine Metrics')]
#[Prefix('vms')]
#[Middleware(['auth:sanctum', 'active'])]
final class VmMetricsController extends Controller
{
    public function __construct(
        private readonly VmMetricsConfigService $config,
        private readonly VmMetricsQueryService $queryService,
        private readonly VmMetricsAuditService $auditService,
    ) {}

    /**
     * Retrieve historical resource utilization metrics for a virtual machine or container.
     */
    #[Get('{virtualMachine}/metrics', name: 'vms.metrics.show', middleware: ['throttle:vm-metrics'])]
    #[PathParameter('virtualMachine', description: 'The ID of the virtual machine or container.', type: 'integer')]
    #[QueryParameter('range', description: 'Time range window (1h, 6h, 24h, 7d, 30d).', type: 'string', default: '1h', example: '1h')]
    #[QueryParameter('metrics', description: 'Comma-separated or array list of metric groups (cpu, memory, disk_space, disk_io, network).', type: 'string', example: 'cpu,memory,disk_space,disk_io,network')]
    #[QueryParameter('max_points', description: 'Maximum data points per series (10..500).', type: 'integer', default: 500, example: 500)]
    public function show(Request $request, int $virtualMachine): JsonResponse|Response
    {
        if (! $this->config->isEnabled()) {
            return response()->json([
                'message' => 'Instance metrics collection and viewing are currently disabled.',
                'code' => 'metrics_disabled',
            ], 503);
        }

        /** @var User|null $user */
        $user = $request->user();
        /** @var VirtualMachine|null $vm */
        $vm = VirtualMachine::find($virtualMachine);

        if (
            $vm === null
            || $vm->status->equals(Deleted::class)
            || $user === null
            || Gate::forUser($user)->inspect('view', $vm)->denied()
        ) {
            throw (new ModelNotFoundException)->setModel(VirtualMachine::class, [$virtualMachine]);
        }

        $validated = VmMetricsRequest::validateQuery($request, $this->config);
        $range = $validated['range'];
        $metrics = $validated['metrics'];
        $maxPoints = $validated['max_points'];

        $this->auditService->recordIfCrossTenant($user, $vm, $range);

        $cacheKey = sprintf(
            'metrics:resp:v1:owner:%d:vm:%d:r:%s:m:%s:p:%d',
            $vm->user_id,
            $vm->id,
            $range,
            implode(',', $metrics),
            $maxPoints
        );

        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['etag'], $cached['payload']) && is_string($cached['etag']) && is_array($cached['payload'])) {
            $etag = $cached['etag'];
            /** @var array<string, mixed> $payload */
            $payload = $cached['payload'];
        } else {
            $payload = $this->queryService->query($vm, $range, $metrics, $maxPoints);
            $encoded = json_encode($payload);
            $etag = 'W/"'.substr(hash('sha256', $encoded !== false ? $encoded : ''), 0, 32).'"';
            $ttl = match ($range) {
                '1h', '6h' => $this->config->intervalSeconds(),
                '24h' => 300,
                default => 3600,
            };
            Cache::put($cacheKey, [
                'etag' => $etag,
                'payload' => $payload,
            ], $ttl);
        }

        $ifNoneMatch = $request->headers->get('If-None-Match');
        if (is_string($ifNoneMatch) && trim($ifNoneMatch) === $etag) {
            return response('', 304)->withHeaders([
                'ETag' => $etag,
                'Cache-Control' => 'private, no-cache',
            ]);
        }

        return response()->json($payload)->withHeaders([
            'ETag' => $etag,
            'Cache-Control' => 'private, no-cache',
        ]);
    }
}
