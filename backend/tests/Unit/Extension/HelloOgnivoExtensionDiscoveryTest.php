<?php

declare(strict_types=1);

use App\Contracts\DnsProviderInterface;
use App\Events\Instance\InstanceCreated;
use App\Services\Extension\ExtensionMetadata;
use App\Services\Extension\ExtensionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Ognivo\HelloOgnivo\HelloOgnivoServiceProvider;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    static $autoloaderRegistered = false;

    if ($autoloaderRegistered) {
        return;
    }

    $prefix = 'Ognivo\\HelloOgnivo\\';
    $candidateDirs = [
        base_path('extensions/hello-ognivo/src'),
        dirname(base_path()).DIRECTORY_SEPARATOR.'extensions'.DIRECTORY_SEPARATOR.'hello-ognivo'.DIRECTORY_SEPARATOR.'src',
    ];

    $resolvedDir = null;
    foreach ($candidateDirs as $candidateDir) {
        if (is_dir($candidateDir)) {
            $resolvedDir = rtrim($candidateDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
            break;
        }
    }

    $baseDir = $resolvedDir ?? base_path('extensions/hello-ognivo/src').DIRECTORY_SEPARATOR;

    spl_autoload_register(static function (string $class) use ($prefix, $baseDir): void {
        if (! str_starts_with($class, $prefix)) {
            return;
        }

        $relativeClass = substr($class, strlen($prefix));
        $file = $baseDir.str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass).'.php';

        if (is_file($file)) {
            require_once $file;
        }
    });

    $autoloaderRegistered = true;
});

it('discovers ognivo/hello-ognivo when scanning base_path extensions', function (): void {
    $registry = new ExtensionRegistry($this->app);

    $discovered = $registry->discover();
    $metadata = $registry->get('ognivo/hello-ognivo');

    expect($discovered)->toHaveKey('ognivo/hello-ognivo')
        ->and($metadata)->toBeInstanceOf(ExtensionMetadata::class)
        ->and($metadata?->name)->toBe('ognivo/hello-ognivo')
        ->and($metadata?->version)->toBe('1.0.0')
        ->and($metadata?->description)->toBe('Reference extension demonstrating Ognivo Core extension points')
        ->and($metadata?->author)->toBe('Ognivo Community')
        ->and($metadata?->ognivoVersionConstraint)->toBe('^1.0')
        ->and($metadata?->providers)->toBe([HelloOgnivoServiceProvider::class])
        ->and($metadata?->eventSubscribers)->toBe([])
        ->and($metadata !== null && $registry->isCompatible($metadata))->toBeTrue();
});

it('boots HelloOgnivoServiceProvider and binds a mock DnsProviderInterface', function (): void {
    Log::spy();

    $registry = new ExtensionRegistry($this->app);
    $registry->registerProviders();

    $dnsProvider = $this->app->make(DnsProviderInterface::class);

    expect($this->app->getProviders(HelloOgnivoServiceProvider::class))->toHaveCount(1)
        ->and($dnsProvider)->toBeInstanceOf(DnsProviderInterface::class)
        ->and($dnsProvider->createRecord('example.com', 'vm1', 'A', '192.0.2.10'))->toBeTrue();

    $records = $dnsProvider->listRecords('example.com');

    expect($records)->toHaveCount(1)
        ->and($records[0]['name'])->toBe('vm1')
        ->and($records[0]['type'])->toBe('A')
        ->and($records[0]['content'])->toBe('192.0.2.10')
        ->and($dnsProvider->deleteRecord('example.com', $records[0]['id']))->toBeTrue()
        ->and($dnsProvider->listRecords('example.com'))->toBe([]);
});

it('logs instance creation via LogInstanceCreationListener when InstanceCreated is dispatched', function (): void {
    Log::spy();

    $registry = new ExtensionRegistry($this->app);
    $registry->registerProviders();

    Event::dispatch(new InstanceCreated(
        instanceId: 42,
        name: 'hello-vm-01',
        hostname: 'hello-vm-01.example.com',
        cpu: 2,
        memoryMb: 2048,
        diskGb: 20,
        image: 'ubuntu/24.04',
    ));

    Log::shouldHaveReceived('info')
        ->once()
        ->with('HelloOgnivo: VM instance created: hello-vm-01', [
            'instance_id' => 42,
        ]);
});
