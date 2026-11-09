<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\IpPool;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\IpManagementService;
use Illuminate\Console\Command;

final class AllocateIpTestCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tests:allocate-ip {pool} {vm}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Allocate an IP for a VM from an IP pool (testing only)';

    /**
     * Execute the console command.
     */
    public function handle(IpManagementService $service): int
    {
        $poolId = (int) $this->argument('pool');
        $vmId = (int) $this->argument('vm');

        /** @var IpPool $pool */
        $pool = IpPool::findOrFail($poolId);
        /** @var VirtualMachine $vm */
        $vm = VirtualMachine::findOrFail($vmId);

        /** @var User $user */
        $user = $vm->user ?? User::findOrFail($vm->user_id);

        $allocation = $service->allocateIp($pool, $vm, $user);
        $this->line($allocation->ip_string);

        return 0;
    }
}
