<?php

declare(strict_types=1);

use App\Contracts\BackupTargetInterface;
use App\Contracts\BillingProviderInterface;
use App\Contracts\Data\VpsAuthorizationInitiator;
use App\Contracts\Data\VpsPlanChangeAuthorizationRequest;
use App\Contracts\Data\VpsPlanChangeAuthorizationResult;
use App\Contracts\Data\VpsProvisioningAuthorizationRequest;
use App\Contracts\Data\VpsProvisioningAuthorizationResult;
use App\Contracts\DnsProviderInterface;
use App\Contracts\NotificationChannelInterface;
use App\Contracts\VpsPlanChangeAuthorizerInterface;
use App\Contracts\VpsProvisioningAuthorizerInterface;

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

/**
 * @return array<class-string, array{interface: class-string, methods: list<string>}>
 */
dataset('experimentalExtensionContracts', [
    VpsPlanChangeAuthorizerInterface::class => [
        'interface' => VpsPlanChangeAuthorizerInterface::class,
        'methods' => ['authorize'],
    ],
    VpsProvisioningAuthorizerInterface::class => [
        'interface' => VpsProvisioningAuthorizerInterface::class,
        'methods' => ['authorize'],
    ],
]);

/**
 * @param  class-string  $interface
 * @param  list<string>  $methods
 */
it('exists as an experimental PHP interface with @experimental docblock and declares the expected public contract methods', function (
    string $interface,
    array $methods,
): void {
    expect(interface_exists($interface))->toBeTrue();

    $reflection = new ReflectionClass($interface);
    $docComment = $reflection->getDocComment();

    expect($reflection->isInterface())->toBeTrue()
        ->and($docComment)->toBeString()->toContain('@experimental');

    foreach ($methods as $methodName) {
        expect($reflection->hasMethod($methodName))->toBeTrue()
            ->and($reflection->getMethod($methodName)->isPublic())->toBeTrue();
    }
})->with('experimentalExtensionContracts');

/**
 * @return array<class-string, array{dataClass: class-string}>
 */
dataset('experimentalExtensionContractData', [
    VpsAuthorizationInitiator::class => [
        'dataClass' => VpsAuthorizationInitiator::class,
    ],
    VpsPlanChangeAuthorizationRequest::class => [
        'dataClass' => VpsPlanChangeAuthorizationRequest::class,
    ],
    VpsPlanChangeAuthorizationResult::class => [
        'dataClass' => VpsPlanChangeAuthorizationResult::class,
    ],
    VpsProvisioningAuthorizationRequest::class => [
        'dataClass' => VpsProvisioningAuthorizationRequest::class,
    ],
    VpsProvisioningAuthorizationResult::class => [
        'dataClass' => VpsProvisioningAuthorizationResult::class,
    ],
]);

/**
 * @param  class-string  $dataClass
 */
it('exists as a final readonly PHP class with @experimental docblock', function (string $dataClass): void {
    expect(class_exists($dataClass))->toBeTrue();

    $reflection = new ReflectionClass($dataClass);
    $docComment = $reflection->getDocComment();

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isReadOnly())->toBeTrue()
        ->and($docComment)->toBeString()->toContain('@experimental');
})->with('experimentalExtensionContractData');
