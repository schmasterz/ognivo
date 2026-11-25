<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Audit\AuditOutcome;
use App\DTO\Cast;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateWebhookEndpointRequest;
use App\Jobs\DeliverWebhookJob;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\RouteAttributes\Attributes\Delete;
use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Middleware;
use Spatie\RouteAttributes\Attributes\Post;
use Spatie\RouteAttributes\Attributes\Prefix;
use Spatie\RouteAttributes\Attributes\WhereNumber;

/**
 * Controller managing administrative CRUD, delivery history, and test pings for webhook endpoints.
 */
#[Prefix('webhooks')]
#[Middleware(['auth:sanctum', 'active', 'admin'])]
final class WebhookController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * List all configured webhook endpoints without exposing their secrets.
     */
    #[Get('', name: 'webhooks.index')]
    public function index(): JsonResponse
    {
        $endpoints = WebhookEndpoint::query()
            ->select([
                'id',
                'url',
                'subscribed_events',
                'is_active',
                'failure_count',
                'created_at',
                'updated_at',
            ])
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $endpoints,
        ]);
    }

    /**
     * Create a new webhook endpoint and return the generated plain-text signing secret once.
     */
    #[Post('', name: 'webhooks.store')]
    public function store(CreateWebhookEndpointRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        $url = trim(Cast::string($validated['url'] ?? ''));

        /** @var list<string> $subscribedEvents */
        $subscribedEvents = [];
        $rawEvents = $validated['subscribed_events'] ?? [];
        if (is_array($rawEvents)) {
            foreach ($rawEvents as $event) {
                $subscribedEvents[] = trim(Cast::string($event));
            }
        }

        $secret = bin2hex(random_bytes(32));

        /** @var WebhookEndpoint $endpoint */
        $endpoint = DB::transaction(function () use ($url, $secret, $subscribedEvents): WebhookEndpoint {
            /** @var WebhookEndpoint $created */
            $created = WebhookEndpoint::query()->create([
                'url' => $url,
                'secret' => $secret,
                'subscribed_events' => $subscribedEvents,
            ]);

            $this->auditLogger->record(new AuditEntry(
                action: 'webhook.created',
                outcome: AuditOutcome::Success,
                subject: $created,
                visibility: 'admin',
                context: [
                    'url' => $created->url,
                    'subscribed_events' => $created->subscribed_events,
                ],
            ));

            return $created;
        });

        return response()->json([
            'data' => $endpoint,
            'secret' => $secret,
        ], 201);
    }

    /**
     * Delete a webhook endpoint and cascade its delivery records.
     */
    #[Delete('{id}', name: 'webhooks.destroy')]
    #[WhereNumber('id')]
    public function destroy(int $id): JsonResponse
    {
        /** @var WebhookEndpoint $endpoint */
        $endpoint = WebhookEndpoint::query()->findOrFail($id);

        DB::transaction(function () use ($endpoint): void {
            $endpoint->delete();

            $this->auditLogger->record(new AuditEntry(
                action: 'webhook.deleted',
                outcome: AuditOutcome::Success,
                subject: $endpoint,
                visibility: 'admin',
                context: [
                    'url' => $endpoint->url,
                ],
            ));
        });

        return response()->json([
            'message' => 'Webhook endpoint deleted successfully.',
        ]);
    }

    /**
     * List past delivery attempts for a given webhook endpoint, ordered newest first.
     */
    #[Get('{id}/deliveries', name: 'webhooks.deliveries')]
    #[WhereNumber('id')]
    public function deliveries(Request $request, int $id): JsonResponse
    {
        /** @var WebhookEndpoint $endpoint */
        $endpoint = WebhookEndpoint::query()->findOrFail($id);

        $perPage = min(max($request->integer('per_page', 25), 1), 100);

        $paginated = WebhookDelivery::query()
            ->where('webhook_endpoint_id', $endpoint->id)
            ->select([
                'id',
                'event_name',
                'response_status',
                'attempt',
                'duration_ms',
                'status',
                'created_at',
            ])
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * Queue a test ping delivery for the specified webhook endpoint.
     */
    #[Post('{id}/test', name: 'webhooks.test')]
    #[WhereNumber('id')]
    public function test(int $id): JsonResponse
    {
        /** @var WebhookEndpoint $endpoint */
        $endpoint = WebhookEndpoint::query()->findOrFail($id);

        /** @var WebhookDelivery $delivery */
        $delivery = WebhookDelivery::query()->create([
            'webhook_endpoint_id' => $endpoint->id,
            'event_name' => 'ping',
            'payload' => [
                'ping' => true,
                'timestamp' => now()->toIso8601String(),
            ],
        ]);

        DeliverWebhookJob::dispatch($delivery);

        return response()->json([
            'message' => 'Test webhook queued successfully.',
            'delivery_id' => $delivery->id,
        ], 202);
    }
}
