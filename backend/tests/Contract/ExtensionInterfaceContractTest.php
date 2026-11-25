<?php

declare(strict_types=1);

use App\Contracts\BackupTargetInterface;
use App\Contracts\BillingProviderInterface;
use App\Contracts\DnsProviderInterface;
use App\Contracts\NotificationChannelInterface;

/**
 * @return array<class-string, array{interface: class-string, methods: list<string>}>
 */
dataset('extensionContracts', [
    DnsProviderInterface::class => [
        'interface' => DnsProviderInterface::class,
        'methods' => ['createRecord', 'deleteRecord', 'listRecords'],
    ],
    NotificationChannelInterface::class => [
        'interface' => NotificationChannelInterface::class,
        'methods' => ['send', 'getIdentifier', 'isConfigured'],
    ],
    BillingProviderInterface::class => [
        'interface' => BillingProviderInterface::class,
        'methods' => ['recordUsage', 'getIdentifier'],
    ],
    BackupTargetInterface::class => [
        'interface' => BackupTargetInterface::class,
        'methods' => ['create', 'restore', 'delete', 'list'],
    ],
]);

it('exists as a PHP interface and declares the expected public contract methods', function (
    string $interface,
    array $methods,
): void {
    expect(interface_exists($interface))->toBeTrue();

    $reflection = new ReflectionClass($interface);

    expect($reflection->isInterface())->toBeTrue();

    foreach ($methods as $methodName) {
        expect($reflection->hasMethod($methodName))->toBeTrue()
            ->and($reflection->getMethod($methodName)->isPublic())->toBeTrue();
    }
})->with('extensionContracts');
