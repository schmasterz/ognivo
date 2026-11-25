<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ServiceTokenFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Override;

/**
 * Model representing a service token (API key) for external services, daemons, or scripts.
 *
 * @property int $id
 * @property string $name
 * @property string $token_hash
 * @property list<string> $abilities
 * @property Carbon|null $last_used_at
 * @property Carbon|null $expires_at
 * @property int|null $created_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $createdBy
 */
final class ServiceToken extends Model
{
    /** @use HasFactory<ServiceTokenFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'token_hash',
        'abilities',
        'last_used_at',
        'expires_at',
        'created_by_user_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'token_hash',
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
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'created_by_user_id' => 'integer',
        ];
    }

    /**
     * The user who created this service token, if any.
     *
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Generate and persist a new service token, returning the model and one-time plain-text secret.
     *
     * @param  list<string>  $abilities
     * @return array{token: self, plain_text_token: string}
     */
    public static function generate(
        string $name,
        array $abilities,
        ?DateTimeInterface $expiresAt = null,
        ?User $creator = null,
    ): array {
        $secret = bin2hex(random_bytes(20));
        $tokenHash = hash('sha256', $secret);

        $model = self::query()->create([
            'name' => $name,
            'token_hash' => $tokenHash,
            'abilities' => $abilities,
            'expires_at' => $expiresAt,
            'created_by_user_id' => $creator?->id,
        ]);

        return [
            'token' => $model,
            'plain_text_token' => 'srv_'.$secret,
        ];
    }

    /**
     * Determine whether the token grants the given ability or wildcard '*'.
     */
    public function hasAbility(string $ability): bool
    {
        return in_array('*', $this->abilities, true) || in_array($ability, $this->abilities, true);
    }

    /**
     * Determine whether the token has not expired.
     */
    public function isValid(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }
}
