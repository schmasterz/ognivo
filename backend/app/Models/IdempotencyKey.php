<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;

/**
 * Model representing an idempotency key record for deduplicating mutating API requests.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $key
 * @property string $request_hash
 * @property int|null $response_status
 * @property array<string, mixed>|null $response_headers
 * @property string|null $response_body
 * @property Carbon|null $locked_at
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class IdempotencyKey extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'key',
        'request_hash',
        'response_status',
        'response_headers',
        'response_body',
        'locked_at',
        'expires_at',
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
            'user_id' => 'integer',
            'response_status' => 'integer',
            'response_headers' => 'array',
            'locked_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Determine whether an in-flight request currently holds an active lock.
     */
    public function isLocked(): bool
    {
        return $this->response_status === null
            && $this->locked_at !== null
            && $this->locked_at->isAfter(now()->subSeconds(30));
    }

    /**
     * Determine whether a response has already been recorded for this key.
     */
    public function isCompleted(): bool
    {
        return $this->response_status !== null;
    }

    /**
     * Determine whether this idempotency record has passed its expiration time.
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Compare a computed request SHA-256 checksum against the stored request hash in constant time.
     */
    public function matchesChecksum(string $hash): bool
    {
        return hash_equals($this->request_hash, $hash);
    }
}
