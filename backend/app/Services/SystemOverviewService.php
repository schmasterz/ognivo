<?php

declare(strict_types=1);

namespace App\Services;

use App\DTO\Cast;
use App\Mail\SmtpTestMail;
use App\Models\Host;
use App\Models\IpAllocation;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Models\VmSnapshot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;

/**
 * Service managing cluster telemetry, infrastructure metrics, and global system flags.
 */
final class SystemOverviewService
{
    /**
     * Compile global resource statistics across all tenant workloads with optional filters.
     *
     * @param  array{user_id?: int|null, host_id?: int|null, status?: string|null, search?: string|null}  $filters
     * @return array{
     *     total_users: int,
     *     active_hosts: int,
     *     total_vms: int,
     *     running_vms: int,
     *     allocated_cpu_cores: int,
     *     allocated_ram_mb: int,
     *     allocated_disk_gb: int,
     *     total_ips: int,
     *     allocated_ips: int,
     *     total_snapshots: int
     * }
     */
    public function getStats(array $filters = []): array
    {
        /** @var Builder<VirtualMachine> $vmQuery */
        $vmQuery = VirtualMachine::whereNotIn('status', ['deleted', 'failed']);

        if (isset($filters['user_id'])) {
            $vmQuery->where('user_id', $filters['user_id']);
        }
        if (isset($filters['host_id'])) {
            $vmQuery->where('host_id', $filters['host_id']);
        }
        if (isset($filters['status']) && $filters['status'] !== '') {
            $status = $filters['status'];
            if (in_array($status, ['active', 'inactive', 'pending'], true)) {
                $vmQuery->whereHas('user', fn (Builder $uq): Builder => $uq->where('status', $status));
            } else {
                $vmQuery->where('status', $status);
            }
        }
        if (isset($filters['search']) && $filters['search'] !== '') {
            $search = $filters['search'];
            $vmQuery->where(function (Builder $q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('incus_project', 'like', "%{$search}%")
                    ->orWhere('ipv4_address', 'like', "%{$search}%")
                    ->orWhereHas('user', function (Builder $uq) use ($search): void {
                        $uq->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        /** @var object{total_vms: int|string, total_cpu: int|float|string, total_ram: int|float|string, total_disk: int|float|string} $vmStats */
        $vmStats = (clone $vmQuery)->selectRaw('COUNT(*) as total_vms,
                     COALESCE(SUM(cpu_cores), 0) as total_cpu,
                     COALESCE(SUM(ram_mb), 0) as total_ram,
                     COALESCE(SUM(disk_gb), 0) as total_disk')
            ->firstOrFail();

        $runningVms = (int) (clone $vmQuery)->where('status', 'running')->count();
        $totalUsers = (int) User::count();
        $activeHosts = (int) Host::where('is_active', true)->count();
        $totalIps = (int) IpAllocation::count();
        $allocatedIps = (int) IpAllocation::where('status', 'allocated')->count();

        $snapshotQuery = VmSnapshot::whereNotIn('status', [VmSnapshot::STATUS_FAILED, VmSnapshot::STATUS_DELETING]);
        if (isset($filters['user_id'])) {
            $snapshotQuery->where('user_id', $filters['user_id']);
        }
        if (isset($filters['host_id']) || (isset($filters['status']) && $filters['status'] !== '') || (isset($filters['search']) && $filters['search'] !== '')) {
            $snapshotQuery->whereIn('virtual_machine_id', (clone $vmQuery)->select('id'));
        }
        $totalSnapshots = (int) $snapshotQuery->count();

        return [
            'total_users' => $totalUsers,
            'active_hosts' => $activeHosts,
            'total_vms' => (int) $vmStats->total_vms,
            'running_vms' => $runningVms,
            'allocated_cpu_cores' => (int) $vmStats->total_cpu,
            'allocated_ram_mb' => (int) $vmStats->total_ram,
            'allocated_disk_gb' => (int) $vmStats->total_disk,
            'total_ips' => $totalIps,
            'allocated_ips' => $allocatedIps,
            'total_snapshots' => $totalSnapshots,
        ];
    }

    /**
     * Check if public user registration is enabled.
     */
    public function isRegistrationEnabled(): bool
    {
        return SystemSetting::isRegistrationEnabled();
    }

    /**
     * Update public user registration toggle.
     */
    public function setRegistrationEnabled(bool $enabled): void
    {
        SystemSetting::updateOrCreate(
            ['key' => 'registration_enabled'],
            ['value' => $enabled]
        );
    }

    /**
     * Check if full virtual machines are enabled.
     */
    public function isVmEnabled(): bool
    {
        return SystemSetting::isVmEnabled();
    }

    /**
     * Check if LXC containers are enabled.
     */
    public function isContainerEnabled(): bool
    {
        return SystemSetting::isContainerEnabled();
    }

    /**
     * Update full virtual machine toggle.
     */
    public function setVmEnabled(bool $enabled): void
    {
        SystemSetting::updateOrCreate(
            ['key' => 'allow_virtual_machines'],
            ['value' => $enabled]
        );
    }

    /**
     * Update LXC container toggle.
     */
    public function setContainerEnabled(bool $enabled): void
    {
        SystemSetting::updateOrCreate(
            ['key' => 'allow_containers'],
            ['value' => $enabled]
        );
    }

    /**
     * Retrieve the public web panel URL.
     */
    public function getFrontendUrl(): string
    {
        return SystemSetting::getFrontendUrl();
    }

    /**
     * Update the public web panel URL.
     */
    public function setFrontendUrl(?string $url): void
    {
        $normalized = ! empty($url) ? rtrim(trim($url), '/') : null;
        SystemSetting::updateOrCreate(
            ['key' => 'frontend_url'],
            ['value' => $normalized]
        );
    }

    /**
     * Retrieve configured SMTP mail server settings (without exposing plain password).
     *
     * @return array{
     *     enabled: bool,
     *     host: string|null,
     *     port: int,
     *     encryption: string,
     *     username: string|null,
     *     has_password: bool,
     *     from_address: string|null,
     *     from_name: string|null
     * }
     */
    public function getSmtpSettings(): array
    {
        $smtp = SystemSetting::getSmtpSettings();

        return [
            'enabled' => $smtp['enabled'],
            'host' => $smtp['host'],
            'port' => $smtp['port'],
            'encryption' => $smtp['encryption'],
            'username' => $smtp['username'],
            'has_password' => ! empty($smtp['password']),
            'from_address' => $smtp['from_address'],
            'from_name' => $smtp['from_name'],
        ];
    }

    /**
     * Update SMTP mail server settings and apply to runtime mail configuration.
     *
     * @param array{
     *     enabled?: bool,
     *     host?: string|null,
     *     port?: int|null,
     *     encryption?: string|null,
     *     username?: string|null,
     *     password?: string|null,
     *     from_address?: string|null,
     *     from_name?: string|null
     * } $data
     */
    public function setSmtpSettings(array $data): void
    {
        $current = SystemSetting::getSmtpSettings();

        $merged = [
            'enabled' => isset($data['enabled']) ? (bool) $data['enabled'] : $current['enabled'],
            'host' => array_key_exists('host', $data) ? $data['host'] : $current['host'],
            'port' => isset($data['port']) ? (int) $data['port'] : $current['port'],
            'encryption' => array_key_exists('encryption', $data) && is_string($data['encryption']) ? $data['encryption'] : $current['encryption'],
            'username' => array_key_exists('username', $data) ? $data['username'] : $current['username'],
            'password' => ! empty($data['password']) ? $data['password'] : $current['password'],
            'from_address' => array_key_exists('from_address', $data) ? $data['from_address'] : $current['from_address'],
            'from_name' => array_key_exists('from_name', $data) ? $data['from_name'] : $current['from_name'],
        ];

        $stored = $merged;
        if (is_string($stored['password']) && $stored['password'] !== '') {
            $stored['password'] = Crypt::encryptString($stored['password']);
        }

        SystemSetting::updateOrCreate(
            ['key' => 'smtp_settings'],
            ['value' => $stored]
        );

        $this->applyMailConfig($merged);

        try {
            Artisan::call('queue:restart');
        } catch (\Throwable) {
            // Silently ignore if cache store or artisan is unavailable
        }
    }

    /**
     * Dynamically inject SMTP parameters into Laravel runtime configuration and purge cached mailers.
     *
     * @param array{
     *     enabled?: bool,
     *     host?: string|null,
     *     port?: int|null,
     *     encryption?: string|null,
     *     username?: string|null,
     *     password?: string|null,
     *     from_address?: string|null,
     *     from_name?: string|null
     * }|null $smtp
     */
    public function applyMailConfig(?array $smtp = null): void
    {
        $current = SystemSetting::getSmtpSettings();
        $enabled = isset($smtp['enabled']) ? (bool) $smtp['enabled'] : $current['enabled'];
        $host = array_key_exists('host', $smtp ?? []) ? $smtp['host'] : $current['host'];
        $port = isset($smtp['port']) ? (int) $smtp['port'] : $current['port'];
        $encryption = isset($smtp['encryption']) ? $smtp['encryption'] : $current['encryption'];
        $username = array_key_exists('username', $smtp ?? []) ? $smtp['username'] : $current['username'];
        $password = array_key_exists('password', $smtp ?? []) ? $smtp['password'] : $current['password'];
        $fromAddress = array_key_exists('from_address', $smtp ?? []) ? $smtp['from_address'] : $current['from_address'];
        $fromName = array_key_exists('from_name', $smtp ?? []) ? $smtp['from_name'] : $current['from_name'];

        if (empty($host)) {
            return;
        }

        $scheme = (strtolower($encryption) === 'ssl' || $port === 465) ? 'smtps' : null;

        config([
            'mail.default' => $enabled ? 'smtp' : 'log',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.host' => $host,
            'mail.mailers.smtp.port' => $port,
            'mail.mailers.smtp.scheme' => $scheme,
            'mail.mailers.smtp.username' => $username,
            'mail.mailers.smtp.password' => $password,
        ]);

        if (! empty($fromAddress)) {
            config(['mail.from.address' => $fromAddress]);
        }
        if (! empty($fromName)) {
            config(['mail.from.name' => $fromName]);
        }

        Mail::purge('smtp');
    }

    /**
     * Send a test verification email through configured SMTP transport.
     *
     * @throws \RuntimeException
     */
    public function sendTestEmail(string $recipientEmail): void
    {
        $smtp = SystemSetting::getSmtpSettings();
        if (empty($smtp['host'])) {
            throw new \RuntimeException((string) __('messages.admin.smtp_disabled'));
        }

        $this->applyMailConfig($smtp);

        $defaultAddress = config('mail.from.address');
        $fromAddress = ! empty($smtp['from_address'])
            ? $smtp['from_address']
            : (is_string($defaultAddress) ? $defaultAddress : 'noreply@ognivo.local');

        $defaultName = config('mail.from.name');
        $fromName = ! empty($smtp['from_name'])
            ? $smtp['from_name']
            : (is_string($defaultName) ? $defaultName : Cast::string(config('app.name', 'Ognivo'), 'Ognivo'));
        Mail::mailer('smtp')
            ->to($recipientEmail)
            ->send(new SmtpTestMail($smtp['host'], $smtp['port'], $fromAddress, $fromName));
    }
}
