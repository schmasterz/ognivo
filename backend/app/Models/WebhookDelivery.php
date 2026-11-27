<?php

declare(strict_types=1);

namespace App\Models;

use App\States\Webhook\WebhookDeliveryState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Override;
use Spatie\ModelStates\HasStates;

/**
 * Model representing a single webhook delivery attempt to an endpoint.
 *
 * @property int $id
 * @property int $webhook_endpoint_id
 * @property string $event_name
 * @property array<string, mixed> $payload
 * @property int|null $response_status
 * @property string|null $response_body
 * @property int $attempt
 * @property int|null $duration_ms
 * @property WebhookDeliveryState $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WebhookEndpoint $endpoint
 */
final class WebhookDelivery extends Model
{
    use HasStates;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RETRYING = 'retrying';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'attempt' => 1,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'webhook_endpoint_id',
        'event_name',
        'payload',
        'response_status',
        'response_body',
        'attempt',
        'duration_ms',
        'status',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'webhook_endpoint_id' => 'integer',
            'payload' => 'array',
            'response_status' => 'integer',
            'attempt' => 'integer',
            'duration_ms' => 'integer',
            'status' => WebhookDeliveryState::class,
        ];
    }

    /**
     * The webhook endpoint this delivery belongs to.
     *
     * @return BelongsTo<WebhookEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
