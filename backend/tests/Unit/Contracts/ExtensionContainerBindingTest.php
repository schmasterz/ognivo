<?php

declare(strict_types=1);

use App\Contracts\BackupTargetInterface;
use App\Contracts\BillingProviderInterface;
use App\Contracts\DnsProviderInterface;
use App\Contracts\NotificationChannelInterface;
use App\Services\Backup\LocalSnapshotTarget;
use Tests\TestCase;

uses(TestCase::class);

it('resolves DnsProviderInterface from the container', function (): void {
    $provider = app(DnsProviderInterface::class);

    expect($provider)->toBeInstanceOf(DnsProviderInterface::class)
        ->and($provider->listRecords('example.com'))->toBe([])
        ->and($provider->createRecord('example.com', 'vm1', 'A', '192.0.2.10'))->toBeFalse()
        ->and($provider->deleteRecord('example.com', 'rec-1'))->toBeFalse();
});

it('resolves NotificationChannelInterface from the container', function (): void {
    $channel = app(NotificationChannelInterface::class);

    expect($channel)->toBeInstanceOf(NotificationChannelInterface::class)
        ->and($channel->getIdentifier())->toBe('log')
        ->and($channel->isConfigured())->toBeTrue()
        ->and($channel->send('ops@example.com', 'Alert', 'Test message'))->toBeTrue();
});

it('resolves BillingProviderInterface from the container', function (): void {
    $billing = app(BillingProviderInterface::class);

    expect($billing)->toBeInstanceOf(BillingProviderInterface::class)
        ->and($billing->getIdentifier())->toBe('null');

    $billing->recordUsage(1, 'vm.uptime_hours', 1.5, ['vm_id' => 10]);
});

it('resolves BackupTargetInterface from the container without overriding AppServiceProvider binding', function (): void {
    $backupTarget = app(BackupTargetInterface::class);

    expect($backupTarget)->toBeInstanceOf(BackupTargetInterface::class)
        ->and($backupTarget)->toBeInstanceOf(LocalSnapshotTarget::class);
});
