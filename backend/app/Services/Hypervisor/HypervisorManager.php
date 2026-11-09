<?php

declare(strict_types=1);

namespace App\Services\Hypervisor;

use App\Exceptions\NoHypervisorAvailableException;
use App\Models\Host;
use App\Models\VirtualMachine;
use App\Services\Hypervisor\Contracts\HypervisorDriverInterface;
use App\Services\Hypervisor\Drivers\FakeHypervisorDriver;
use App\Services\Hypervisor\Drivers\IncusDriver;
use App\Services\Hypervisor\Drivers\LxdDriver;

/**
 * Hypervisor manager and driver resolver.
 */
final class HypervisorManager
{
    private static ?HypervisorDriverInterface $fakeDriver = null;

    /**
     * Set a fake driver for testing.
     */
    public static function fake(?HypervisorDriverInterface $driver = null): HypervisorDriverInterface
    {
        return self::$fakeDriver = $driver ?? new FakeHypervisorDriver;
    }

    /**
     * Reset the fake driver.
     */
    public static function resetFake(): void
    {
        self::$fakeDriver = null;
    }

    /**
     * Resolve the hypervisor driver for a specific VM.
     */
    public function driverForVm(VirtualMachine $vm): HypervisorDriverInterface
    {
        if (self::$fakeDriver !== null) {
            return self::$fakeDriver;
        }

        $host = $vm->host;

        return $this->driverForHost($host);
    }

    /**
     * Resolve the hypervisor driver for a specific host.
     */
    public function driverForHost(?Host $host): HypervisorDriverInterface
    {
        if (self::$fakeDriver !== null) {
            return self::$fakeDriver;
        }

        if ($host === null) {
            throw new NoHypervisorAvailableException('No hypervisor host is assigned or available.');
        }

        if ($host->driver === 'fake') {
            if (app()->environment(['local', 'testing']) || (bool) config('app.allow_fake_driver')) {
                return new FakeHypervisorDriver;
            }

            throw new NoHypervisorAvailableException('Fake hypervisor driver is not allowed outside local or testing environments.');
        }

        if ($host->driver === 'lxd') {
            return new LxdDriver($host);
        }

        return new IncusDriver($host);
    }
}
