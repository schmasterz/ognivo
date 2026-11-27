<?php

use App\Jobs\WarnExpiringPanelCertificateJob;
use App\Models\IpPool;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\IpManagementService;
use App\Services\VmProvisioningService;
use Illuminate\Console\Command;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Symfony\Component\Console\Output\ConsoleOutputInterface;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new WarnExpiringPanelCertificateJob)->daily();
Schedule::command('vm:reconcile')->everyFiveMinutes()->withoutOverlapping(10)->runInBackground();
Schedule::command('vm:sweep-stale-provisioning')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('vps:reconcile-plan-changes')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('snapshots:sync')->hourly()->withoutOverlapping(15)->runInBackground();
Schedule::call(fn (IpManagementService $service) => $service->pruneExpiredCoolingAllocations())
    ->everyMinute()
    ->name('ipam:prune-cooling');

Schedule::command('audit:seal')->everyMinute()->withoutOverlapping();
Schedule::command('audit:verify')->hourly();
Schedule::command('audit:prune')->daily();
Schedule::command('images:verify-catalog')->daily();
Schedule::command('hosts:sync-resources')->hourly()->withoutOverlapping();
Schedule::command('metrics:collect')->everyMinute()->withoutOverlapping(2);
Schedule::command('metrics:rollup')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('metrics:prune')->hourly()->withoutOverlapping(15);

if (app()->environment('testing')) {
    Artisan::command('tests:allocate-ip {pool} {vm}', function (IpManagementService $service): int {
        /** @var Command $this */
        $poolId = (int) $this->argument('pool');
        $vmId = (int) $this->argument('vm');

        /** @var IpPool $pool */
        $pool = IpPool::findOrFail($poolId);
        /** @var VirtualMachine $vm */
        $vm = VirtualMachine::findOrFail($vmId);

        $allocation = $service->allocateIp($pool, $vm, $vm->user);
        $this->line($allocation->ip_string);

        return 0;
    })->purpose('Allocate an IP for a VM from an IP pool in testing');

    Artisan::command('tests:provision-vm {user} {host} {cpu} {ram} {disk}', function (VmProvisioningService $service): int {
        /** @var Command $this */
        $userId = (int) $this->argument('user');
        $hostId = (int) $this->argument('host');
        $cpu = (int) $this->argument('cpu');
        $ram = (int) $this->argument('ram');
        $disk = (int) $this->argument('disk');

        /** @var User $user */
        $user = User::findOrFail($userId);

        try {
            $vm = $service->provision($user, [
                'name' => 'concurrent-vm-'.uniqid(),
                'cpu_cores' => $cpu,
                'ram_mb' => $ram,
                'disk_gb' => $disk,
                'os_image' => 'ubuntu:24.04',
                'host_id' => $hostId,
            ]);

            $this->line('SUCCESS:'.$vm->id);

            return 0;
        } catch (Throwable $e) {
            $msg = 'ERROR: '.$e->getMessage();
            if ($this->getOutput() instanceof ConsoleOutputInterface) {
                $this->getOutput()->getErrorOutput()->writeln($msg);
            }
            $this->error($msg);

            return 1;
        }
    })->purpose('Provision a VM for integration concurrency testing');
}
