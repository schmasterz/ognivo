<?php

declare(strict_types=1);

namespace App\Models;

use App\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;

/**
 * Model representing persistent platform settings and system defaults.
 *
 * @property int $id
 * @property string $key
 * @property array<string, mixed>|mixed $value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class SystemSetting extends Model
{
    use Auditable;

    /**
     * Whitelist of attributes tracked for audit logging.
     *
     * @var list<string>
     */
    protected array $auditable = [
        'key',
        'value',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'value',
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
            'value' => 'array',
        ];
    }

    /**
     * Get a setting by key with an optional default fallback.
     */
    public static function getSetting(string $key, mixed $default = null): mixed
    {
        /** @var self|null $setting */
        $setting = self::where('key', $key)->first();

        return $setting !== null ? $setting->value : $default;
    }

    /**
     * Check if tenant user registration is enabled.
     */
    public static function isRegistrationEnabled(): bool
    {
        /** @var mixed $setting */
        $setting = self::getSetting('registration_enabled', true);

        return $setting === true || $setting === 'true' || $setting === 1 || $setting === '1';
    }

    /**
     * Check if email verification is enabled for user registration.
     */
    public static function isRegistrationVerificationEnabled(): bool
    {
        /** @var mixed $setting */
        $setting = self::getSetting('registration_verification_enabled', true);

        return $setting === true || $setting === 'true' || $setting === 1 || $setting === '1';
    }

    /**
     * Check if full virtual machines are enabled on the platform.
     */
    public static function isVmEnabled(): bool
    {
        /** @var mixed $setting */
        $setting = self::getSetting('allow_virtual_machines', true);

        return $setting === true || $setting === 'true' || $setting === 1 || $setting === '1';
    }

    /**
     * Check if LXC containers are enabled on the platform.
     */
    public static function isContainerEnabled(): bool
    {
        /** @var mixed $setting */
        $setting = self::getSetting('allow_containers', true);

        return $setting === true || $setting === 'true' || $setting === 1 || $setting === '1';
    }

    /**
     * Retrieve the public web panel URL for email links and notifications.
     */
    public static function getFrontendUrl(): string
    {
        /** @var mixed $setting */
        $setting = self::getSetting('frontend_url');
        if (is_string($setting) && trim($setting) !== '') {
            return rtrim(trim($setting), '/');
        }

        $appUrl = config('app.url');
        if (is_string($appUrl) && trim($appUrl) !== '') {
            return rtrim(trim($appUrl), '/');
        }

        return 'http://localhost';
    }

    /**
     * Retrieve the configured system default quota structure.
     *
     * @return array{max_vms: int, max_cpu_cores: int, max_ram_mb: int, max_storage_gb: int, max_ips: int, max_snapshots: int}
     */
    public static function getDefaultQuota(): array
    {
        $defaultGroup = QuotaGroup::getDefaultGroup();
        if ($defaultGroup !== null) {
            return [
                'max_vms' => $defaultGroup->max_vms,
                'max_cpu_cores' => $defaultGroup->max_cpu_cores,
                'max_ram_mb' => $defaultGroup->max_ram_mb,
                'max_storage_gb' => $defaultGroup->max_storage_gb,
                'max_ips' => $defaultGroup->max_ips,
                'max_snapshots' => $defaultGroup->max_snapshots ?? 3,
            ];
        }

        $default = [
            'max_vms' => 2,
            'max_cpu_cores' => 4,
            'max_ram_mb' => 4096,
            'max_storage_gb' => 80,
            'max_ips' => 1,
            'max_snapshots' => 3,
        ];

        /** @var mixed $setting */
        $setting = self::getSetting('default_quota', $default);

        if (is_array($setting)) {
            $vms = $setting['max_vms'] ?? null;
            $cpu = $setting['max_cpu_cores'] ?? null;
            $ram = $setting['max_ram_mb'] ?? null;
            $disk = $setting['max_storage_gb'] ?? null;
            $ips = $setting['max_ips'] ?? null;
            $snapshots = $setting['max_snapshots'] ?? null;

            return [
                'max_vms' => is_numeric($vms) ? (int) $vms : $default['max_vms'],
                'max_cpu_cores' => is_numeric($cpu) ? (int) $cpu : $default['max_cpu_cores'],
                'max_ram_mb' => is_numeric($ram) ? (int) $ram : $default['max_ram_mb'],
                'max_storage_gb' => is_numeric($disk) ? (int) $disk : $default['max_storage_gb'],
                'max_ips' => is_numeric($ips) ? (int) $ips : $default['max_ips'],
                'max_snapshots' => is_numeric($snapshots) ? (int) $snapshots : $default['max_snapshots'],
            ];
        }

        return $default;
    }

    /**
     * Retrieve configured SMTP mail server settings.
     *
     * @return array{
     *     enabled: bool,
     *     host: string|null,
     *     port: int,
     *     encryption: string,
     *     username: string|null,
     *     password: string|null,
     *     from_address: string|null,
     *     from_name: string|null
     * }
     */
    public static function getSmtpSettings(): array
    {
        $default = [
            'enabled' => true,
            'host' => null,
            'port' => 587,
            'encryption' => 'tls',
            'username' => null,
            'password' => null,
            'from_address' => null,
            'from_name' => null,
        ];

        /** @var mixed $setting */
        $setting = self::getSetting('smtp_settings', $default);

        if (is_array($setting)) {
            return [
                'enabled' => (bool) ($setting['enabled'] ?? true),
                'host' => isset($setting['host']) && is_string($setting['host']) ? $setting['host'] : null,
                'port' => isset($setting['port']) && is_numeric($setting['port']) ? (int) $setting['port'] : 587,
                'encryption' => isset($setting['encryption']) && is_string($setting['encryption']) ? $setting['encryption'] : 'tls',
                'username' => isset($setting['username']) && is_string($setting['username']) ? $setting['username'] : null,
                'password' => isset($setting['password']) && is_string($setting['password']) ? $setting['password'] : null,
                'from_address' => isset($setting['from_address']) && is_string($setting['from_address']) ? $setting['from_address'] : null,
                'from_name' => isset($setting['from_name']) && is_string($setting['from_name']) ? $setting['from_name'] : null,
            ];
        }

        return $default;
    }
}
