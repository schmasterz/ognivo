<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IpAllocationStatus;
use App\Enums\NetworkStatus;
use App\Events\AdminVirtualMachineStatusUpdated;
use App\Events\VirtualMachineStatusUpdated;
use App\Jobs\PurgeVmMetricsJob;
use App\States\Vm\Deleted;
use App\States\Vm\VmState;
use Database\Factories\VirtualMachineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Override;
use Spatie\ModelStates\HasStates;

/**
 * Model representing a provisioned or pending Virtual Machine / Container instance.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $host_id
 * @property int|null $vps_plan_version_id
 * @property string $name
 * @property string $incus_project
 * @property string $instance_type
 * @property VmState $status
 * @property int $cpu_cores
 * @property int $ram_mb
 * @property int $disk_gb
 * @property int|null $net_download_mbps
 * @property int|null $net_upload_mbps
 * @property string $os_image
 * @property string|null $ipv4_address
 * @property string|null $ipv6_address
 * @property-read string|null $mac_address
 * @property NetworkStatus|null $network_status
 * @property Carbon|null $network_wait_started_at
 * @property array<string, mixed>|null $cloud_init
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class VirtualMachine extends Model
{
    /** @use HasFactory<VirtualMachineFactory> */
    use HasFactory;

    use HasStates;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'host_id',
        'name',
        'incus_project',
        'instance_type',
        'status',
        'cpu_cores',
        'ram_mb',
        'disk_gb',
        'os_image',
        'ipv4_address',
        'ipv6_address',
        'network_status',
        'network_wait_started_at',
        'cloud_init',
        'metadata',
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
            'host_id' => 'integer',
            'vps_plan_version_id' => 'integer',
            'cpu_cores' => 'integer',
            'ram_mb' => 'integer',
            'disk_gb' => 'integer',
            'net_download_mbps' => 'integer',
            'net_upload_mbps' => 'integer',
            'cloud_init' => 'array',
            'metadata' => 'array',
            'status' => VmState::class,
            'network_status' => NetworkStatus::class,
            'network_wait_started_at' => 'datetime',
        ];
    }

    /**
     * The owner of this virtual machine.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The host running this virtual machine.
     *
     * @return BelongsTo<Host, $this>
     */
    public function host(): BelongsTo
    {
        return $this->belongsTo(Host::class);
    }

    /**
     * The VPS plan version associated with this virtual machine.
     *
     * @return BelongsTo<VpsPlanVersion, $this>
     */
    public function vpsPlanVersion(): BelongsTo
    {
        return $this->belongsTo(VpsPlanVersion::class, 'vps_plan_version_id');
    }

    /**
     * OS Image catalog definition for this workload.
     *
     * @return BelongsTo<Image, $this>
     */
    public function image(): BelongsTo
    {
        return $this->belongsTo(Image::class, 'os_image', 'name');
    }

    /**
     * IP allocations assigned to this virtual machine.
     *
     * @return HasMany<IpAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(IpAllocation::class, 'virtual_machine_id');
    }

    /**
     * Primary active IP allocation assigned to this virtual machine.
     *
     * @return HasOne<IpAllocation, $this>
     */
    public function primaryAllocation(): HasOne
    {
        return $this->hasOne(IpAllocation::class, 'virtual_machine_id')->where('status', IpAllocationStatus::Allocated->value);
    }

    /**
     * MAC address derived from primary active allocation.
     */
    public function getMacAddressAttribute(): ?string
    {
        /** @var IpAllocation|null $allocation */
        $allocation = $this->relationLoaded('primaryAllocation')
            ? $this->getRelation('primaryAllocation')
            : $this->primaryAllocation()->first();

        return $allocation?->mac_address;
    }

    /**
     * State drift reports recorded for this virtual machine.
     *
     * @return HasMany<DriftReport, $this>
     */
    public function driftReports(): HasMany
    {
        return $this->hasMany(DriftReport::class);
    }

    /**
     * Snapshots captured for this virtual machine.
     *
     * @return HasMany<VmSnapshot, $this>
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(VmSnapshot::class, 'virtual_machine_id');
    }

    /**
     * Transient property tracking whether to broadcast full resource or compact delta.
     */
    public ?string $wsBroadcastType = null;

    /**
     * Bootstrap model events to broadcast real-time state transitions via WebSockets.
     */
    #[Override]
    protected static function booted(): void
    {
        self::saving(function (self $vm): void {
            $runtimeKeys = ['status', 'ipv4_address', 'network_status', 'metadata'];
            $structuralKeys = ['name', 'host_id', 'cpu_cores', 'ram_mb', 'disk_gb', 'ipv6_address', 'os_image', 'incus_project', 'instance_type'];

            if (! $vm->exists || $vm->isDirty($structuralKeys)) {
                $vm->wsBroadcastType = 'full';
            } elseif ($vm->isDirty($runtimeKeys)) {
                $vm->wsBroadcastType = 'delta';
            } else {
                $vm->wsBroadcastType = null;
            }
        });

        self::saved(function (self $vm): void {
            if ($vm->status->equals(Deleted::class)) {
                try {
                    $vm->snapshots()->delete();
                } catch (\Throwable) {
                    // Ignore if table not yet migrated in isolated unit tests
                }

                self::purgeMetricsCacheAndDispatch($vm);
            }

            if ($vm->wsBroadcastType !== null) {
                $broadcastType = $vm->wsBroadcastType;
                $vm->wsBroadcastType = null;

                try {
                    $isDelta = ($broadcastType === 'delta');
                    event(new VirtualMachineStatusUpdated($vm, $isDelta));
                    event(new AdminVirtualMachineStatusUpdated($vm, $isDelta));
                } catch (\Throwable) {
                    // Silently ignore broadcast failure so transactions never fail
                }
            }
        });

        self::deleted(function (self $vm): void {
            self::purgeMetricsCacheAndDispatch($vm);
        });
    }

    /**
     * Clear cached metric counters/totals and dispatch async DB purge for a deleted VM.
     */
    private static function purgeMetricsCacheAndDispatch(self $vm): void
    {
        try {
            Cache::forget("metrics:counters:{$vm->id}");
            Cache::forget("metrics:totals_latest:{$vm->id}");
            PurgeVmMetricsJob::dispatch($vm->id);
        } catch (\Throwable) {
            // Ignore if queue or cache is unavailable in isolated unit tests
        }
    }
}
