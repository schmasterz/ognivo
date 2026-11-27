<?php

declare(strict_types=1);

use App\Enums\NetworkStatus;
use App\Events\AdminVirtualMachineStatusUpdated;
use App\Events\VirtualMachineStatusUpdated;
use App\Http\Resources\VirtualMachineResource;
use App\Models\User;
use App\Models\VirtualMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('defines all six expected NetworkStatus cases and string values', function (): void {
    expect(array_map(static fn (NetworkStatus $status): string => $status->value, NetworkStatus::cases()))
        ->toBe([
            'assigned',
            'waiting',
            'observed',
            'unverified',
            'mismatch',
            'unassigned',
        ]);
});

it('casts VirtualMachine network_status to NetworkStatus enum or null', function (): void {
    $user = User::factory()->create();

    $vmNull = VirtualMachine::factory()->create([
        'user_id' => $user->id,
        'network_status' => null,
    ]);
    expect($vmNull->fresh()?->network_status)->toBeNull();

    foreach (NetworkStatus::cases() as $status) {
        $vm = VirtualMachine::factory()->create([
            'user_id' => $user->id,
            'network_status' => $status,
        ]);

        expect($vm->fresh()?->network_status)->toBe($status);
    }
});

it('serializes NetworkStatus to string value in resource and delta broadcast events', function (): void {
    $user = User::factory()->create();
    $vm = VirtualMachine::factory()->create([
        'user_id' => $user->id,
        'network_status' => NetworkStatus::Observed,
    ]);

    $resourceData = (new VirtualMachineResource($vm))->toArray(Request::create('/'));
    expect($resourceData['network_status'])->toBe('observed')
        ->and($resourceData['network']['primary']['status'])->toBe('observed')
        ->and($resourceData['network']['observed_at'])->not->toBeNull();

    $tenantEvent = new VirtualMachineStatusUpdated($vm, isDelta: true);
    $adminEvent = new AdminVirtualMachineStatusUpdated($vm, isDelta: true);

    expect($tenantEvent->vm['network_status'])->toBe('observed')
        ->and($adminEvent->vm['network_status'])->toBe('observed');

    $vm->network_status = NetworkStatus::Assigned;
    $vm->save();

    $assignedResourceData = (new VirtualMachineResource($vm->fresh()))->toArray(Request::create('/'));
    expect($assignedResourceData['network_status'])->toBe('assigned')
        ->and($assignedResourceData['network']['primary']['status'])->toBe('assigned')
        ->and($assignedResourceData['network']['observed_at'])->toBeNull();
});
