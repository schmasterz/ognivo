<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Enums\QueueName;
use App\Models\WebhookDelivery;
use App\States\Webhook\Failed;
use App\States\Webhook\Retrying;
use App\States\Webhook\Success;
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
use Throwable;

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
        if ($this->delivery->status->equals(Success::class)) {
            return;
        }

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
            $this->recordFailure(
                responseStatus: null,
                responseBody: $this->truncateResponseBody($e->getMessage()),
                attempt: $attempt,
                durationMs: $this->elapsedMs($startNs),
            );

            $endpoint->increment('failure_count');

            throw $e;
        }

        $durationMs = $this->elapsedMs($startNs);
        $responseBody = $this->truncateResponseBody($response->body());

        if ($response->successful()) {
            $this->delivery->fill([
                'response_status' => $response->status(),
                'response_body' => $responseBody,
                'attempt' => $attempt,
                'duration_ms' => $durationMs,
            ]);
            $this->delivery->status->transitionTo(Success::class);

            $endpoint->update(['failure_count' => 0]);

            return;
        }

        $this->recordFailure(
            responseStatus: $response->status(),
            responseBody: $responseBody,
            attempt: $attempt,
            durationMs: $durationMs,
        );

        $endpoint->increment('failure_count');

        $response->throw();

        throw new RuntimeException(sprintf(
            'Webhook delivery #%d failed with unexpected HTTP status %d.',
            $this->delivery->id,
            $response->status(),
        ));
    }

    /**
     * Transition delivery to Failed once all queue retries are exhausted.
     */
    public function failed(?Throwable $exception): void
    {
        $freshDelivery = $this->delivery->fresh();
        if ($freshDelivery === null) {
            return;
        }

        if ($freshDelivery->status->canTransitionTo(Failed::class)) {
            $freshDelivery->status->transitionTo(Failed::class);
        }
    }

    /**
     * Persist failure diagnostics and transition to Retrying (if retries remain) or Failed.
     */
    private function recordFailure(?int $responseStatus, string $responseBody, int $attempt, int $durationMs): void
    {
        $this->delivery->fill([
            'response_status' => $responseStatus,
            'response_body' => $responseBody,
            'attempt' => $attempt,
            'duration_ms' => $durationMs,
        ]);

        if ($attempt >= $this->tries) {
            if (! $this->delivery->status->equals(Failed::class)) {
                $this->delivery->status->transitionTo(Failed::class);
            } else {
                $this->delivery->save();
            }

            return;
        }

        if (! $this->delivery->status->equals(Retrying::class)) {
            $this->delivery->status->transitionTo(Retrying::class);
        } else {
            $this->delivery->save();
        }
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
