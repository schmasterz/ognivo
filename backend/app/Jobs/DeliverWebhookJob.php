<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Enums\QueueName;
use App\Models\WebhookDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Queued job responsible for delivering a signed webhook payload via HTTP POST.
 */
final class DeliverWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const MAX_RESPONSE_BODY_LENGTH = 1000;

    public int $tries = 4;

    /**
     * Exponential retry backoff intervals in seconds.
     *
     * @var list<int>
     */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(
        public readonly WebhookDelivery $delivery,
    ) {
        $this->onQueue(QueueName::Default);
    }

    /**
     * Execute the webhook HTTP delivery and record the outcome.
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function handle(): void
    {
        $endpoint = $this->delivery->endpoint()->first();
        if ($endpoint === null) {
            return;
        }

        $attempt = max(1, $this->attempts());
        $body = json_encode($this->delivery->payload, JSON_THROW_ON_ERROR);
        $signature = 'sha256='.$endpoint->signPayload($body);

        $startNs = (float) hrtime(true);

        try {
            /** @var Response $response */
            $response = Http::timeout(10)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Ognivo-Event' => $this->delivery->event_name,
                    'X-Ognivo-Signature' => $signature,
                    'X-Ognivo-Delivery' => (string) $this->delivery->id,
                ])
                ->withBody($body, 'application/json')
                ->post($endpoint->url);
        } catch (ConnectionException $e) {
            $this->delivery->update([
                'status' => WebhookDelivery::STATUS_FAILED,
                'response_status' => null,
                'response_body' => $this->truncateResponseBody($e->getMessage()),
                'attempt' => $attempt,
                'duration_ms' => $this->elapsedMs($startNs),
            ]);

            $endpoint->increment('failure_count');

            throw $e;
        }

        $durationMs = $this->elapsedMs($startNs);
        $responseBody = $this->truncateResponseBody($response->body());

        if ($response->successful()) {
            $this->delivery->update([
                'status' => WebhookDelivery::STATUS_SUCCESS,
                'response_status' => $response->status(),
                'response_body' => $responseBody,
                'attempt' => $attempt,
                'duration_ms' => $durationMs,
            ]);

            $endpoint->update(['failure_count' => 0]);

            return;
        }

        $this->delivery->update([
            'status' => WebhookDelivery::STATUS_FAILED,
            'response_status' => $response->status(),
            'response_body' => $responseBody,
            'attempt' => $attempt,
            'duration_ms' => $durationMs,
        ]);

        $endpoint->increment('failure_count');

        $response->throw();

        throw new RuntimeException(sprintf(
            'Webhook delivery #%d failed with unexpected HTTP status %d.',
            $this->delivery->id,
            $response->status(),
        ));
    }

    /**
     * Compute elapsed milliseconds since the given hrtime(true) start timestamp.
     */
    private function elapsedMs(float $startNs): int
    {
        return max(0, (int) round(((float) hrtime(true) - $startNs) / 1_000_000.0));
    }

    /**
     * Truncate response bodies to a bounded length for database storage.
     */
    private function truncateResponseBody(string $body): string
    {
        return mb_substr($body, 0, self::MAX_RESPONSE_BODY_LENGTH);
    }
}
