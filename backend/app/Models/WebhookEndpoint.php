<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Override;

/**
 * Model representing a configured external webhook endpoint.
 *
 * @property int $id
 * @property string $url
 * @property string $secret
 * @property list<string> $subscribed_events
 * @property bool $is_active
 * @property int $failure_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, WebhookDelivery> $deliveries
 */
final class WebhookEndpoint extends Model
{
    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'failure_count' => 0,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'url',
        'secret',
        'subscribed_events',
        'is_active',
        'failure_count',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'secret',
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
            'subscribed_events' => 'array',
            'is_active' => 'boolean',
            'failure_count' => 'integer',
        ];
    }

    /**
     * Delivery attempts recorded for this webhook endpoint.
     *
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'webhook_endpoint_id');
    }

    /**
     * Determine whether this endpoint is subscribed to the given event name.
     */
    public function isSubscribedTo(string $eventName): bool
    {
        if (in_array('*', $this->subscribed_events, true) || in_array($eventName, $this->subscribed_events, true)) {
            return true;
        }

        foreach ($this->subscribed_events as $pattern) {
            if (str_ends_with($pattern, '.*') && str_starts_with($eventName, substr($pattern, 0, -1))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compute the HMAC-SHA256 signature for a serialized webhook payload.
     */
    public function signPayload(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->secret);
    }
}
