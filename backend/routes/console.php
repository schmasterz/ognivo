<?php

use App\Jobs\WarnExpiringPanelCertificateJob;
use App\Models\IpPool;
use App\Models\VirtualMachine;
use App\Services\IpManagementService;
use Illuminate\Console\Command;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new WarnExpiringPanelCertificateJob)->daily();
Schedule::command('vm:reconcile')->everyFiveMinutes();
Schedule::call(fn (IpManagementService $service) => $service->pruneExpiredCoolingAllocations())
    ->everyMinute()
    ->name('ipam:prune-cooling');

Schedule::command('audit:seal')->everyMinute()->withoutOverlapping();
Schedule::command('audit:verify')->hourly();
Schedule::command('audit:prune')->daily();

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
}
