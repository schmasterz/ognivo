<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\ImageAttachmentService;
use Database\Factories\ImageFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Override;

/**
 * Model representing an administrator-approved image allowlist entry.
 *
 * @property int $id
 * @property string $name
 * @property string $source
 * @property string $protocol
 * @property string $os_type
 * @property bool $enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Host> $hosts
 */
final class Image extends Model
{
    /** @use HasFactory<ImageFactory> */
    use HasFactory;

    /**
     * Bootstrap model events to attach newly registered images to eligible compute nodes.
     */
    #[Override]
    protected static function booted(): void
    {
        self::created(function (Image $image): void {
            try {
                app(ImageAttachmentService::class)->attachToAutoAttachHosts($image);
            } catch (\Throwable) {
                // Silently ignore during migrations or before schema readiness
            }
        });
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'source',
        'protocol',
        'os_type',
        'enabled',
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
            'enabled' => 'boolean',
        ];
    }

    /**
     * Compute nodes associated with this image.
     *
     * @return BelongsToMany<Host, $this>
     */
    public function hosts(): BelongsToMany
    {
        return $this->belongsToMany(Host::class, 'host_image')->withTimestamps();
    }
}
