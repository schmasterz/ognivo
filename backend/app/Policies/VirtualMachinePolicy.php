<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\VirtualMachine;
use Illuminate\Auth\Access\Response;

/**
 * Eloquent authorization policy for Virtual Machines and LXC Containers.
 */
final class VirtualMachinePolicy
{
    /**
     * Determine whether the user can view the virtual machine details.
     */
    public function view(User $user, VirtualMachine $vm): Response
    {
        if ($user->hasRole('admin') || $vm->user_id === $user->id) {
            return Response::allow();
        }

        $msg = __('messages.vm.unauthorized_access');

        return Response::deny(
            is_string($msg) ? $msg : 'Unauthorized access.'
        );
    }

    /**
     * Determine whether the user can execute lifecycle actions (start, stop, restart, delete).
     */
    public function executeAction(User $user, VirtualMachine $vm): Response
    {
        if ($user->hasRole('admin') || $vm->user_id === $user->id) {
            return Response::allow();
        }

        $msg = __('messages.vm.unauthorized_access');

        return Response::deny(
            is_string($msg) ? $msg : 'Unauthorized access.'
        );
    }

    /**
     * Determine whether the user can view snapshots for the virtual machine.
     */
    public function viewSnapshots(User $user, VirtualMachine $vm): Response
    {
        return $this->view($user, $vm);
    }

    /**
     * Determine whether the user can create, restore, or delete snapshots for the virtual machine.
     */
    public function manageSnapshots(User $user, VirtualMachine $vm): Response
    {
        return $this->executeAction($user, $vm);
    }

    /**
     * Determine whether the user can access the interactive web console.
     *
     * Privacy constraint: Administrators are strictly forbidden from guest console access.
     * Only the workload tenant owner is permitted access.
     */
    public function viewConsole(User $user, VirtualMachine $vm): Response
    {
        if ($user->hasRole('admin')) {
            $msg = __('messages.vm.console_access_forbidden');

            return Response::deny(
                is_string($msg) ? $msg : 'Console access is restricted to the workload owner only. Administrators are denied guest access.'
            );
        }

        if ($vm->user_id !== $user->id) {
            $msg = __('messages.vm.unauthorized_access');

            return Response::deny(
                is_string($msg) ? $msg : 'Unauthorized access.'
            );
        }

        return Response::allow();
    }
}
