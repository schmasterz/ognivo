<?php

declare(strict_types=1);

use App\Services\Extension\ExtensionMetadata;
use App\Services\Extension\ExtensionRegistry;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class CompatibleExtensionTestProvider extends ServiceProvider
{
    public static int $registerCount = 0;

    public function register(): void
    {
        self::$registerCount++;
        $this->app->instance('test.compatible.extension', 'registered');
    }
}

final class IncompatibleExtensionTestProvider extends ServiceProvider
{
    public static int $registerCount = 0;

    public function register(): void
    {
        self::$registerCount++;
        $this->app->instance('test.incompatible.extension', 'registered');
    }
}

function createTempBaseDir(): string
{
    $dir = sys_get_temp_dir().'/ognivo-ext-registry-'.bin2hex(random_bytes(8));
    mkdir($dir, 0777, true);

    return $dir;
}

function removeDirectoryRecursively(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    $items = scandir($dir);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir.DIRECTORY_SEPARATOR.$item;
        if (is_dir($path) && ! is_link($path)) {
            removeDirectoryRecursively($path);
        } else {
            unlink($path);
        }
    }

    rmdir($dir);
}

/**
 * @param  array<string, mixed>  $manifest
 */
function writeExtensionManifest(string $dir, array $manifest): void
{
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    file_put_contents(
        $dir.DIRECTORY_SEPARATOR.'ognivo-extension.json',
        json_encode($manifest, JSON_THROW_ON_ERROR)
    );
}

beforeEach(function (): void {
    $this->tempDirs = [];
    CompatibleExtensionTestProvider::$registerCount = 0;
    IncompatibleExtensionTestProvider::$registerCount = 0;
});

afterEach(function (): void {
    foreach ($this->tempDirs as $dir) {
        removeDirectoryRecursively($dir);
    }
});

it('discovers extension manifests from local extensions and vendor directories', function (): void {
    $baseDir = createTempBaseDir();
    $this->tempDirs[] = $baseDir;

    writeExtensionManifest($baseDir.'/extensions/local-billing', [
        'name' => 'ognivo/local-billing',
        'version' => '1.0.0',
        'description' => 'Local billing extension',
        'author' => 'Ognivo Team',
        'ognivo_version_constraint' => '^1.0',
        'providers' => [CompatibleExtensionTestProvider::class],
    ]);

    writeExtensionManifest($baseDir.'/vendor/acme/dns-sync', [
        'name' => 'acme/dns-sync',
        'version' => '2.1.0',
        'ognivo_version_constraint' => '^1.0',
    ]);

    // Also include a package via composer/installed.json install-path outside vendor/*/*
    $customPkgDir = $baseDir.'/custom-packages/extra-ext';
    writeExtensionManifest($customPkgDir, [
        'name' => 'acme/installed-json-ext',
        'version' => '0.5.0',
        'ognivo_version_constraint' => '^1.0',
    ]);

    mkdir($baseDir.'/vendor/composer', 0777, true);
    file_put_contents(
        $baseDir.'/vendor/composer/installed.json',
        json_encode([
            'packages' => [
                [
                    'name' => 'acme/dns-sync',
                    'install-path' => '../acme/dns-sync',
                ],
                [
                    'name' => 'acme/installed-json-ext',
                    'install-path' => '../../custom-packages/extra-ext',
                ],
            ],
        ], JSON_THROW_ON_ERROR)
    );

    $app = new Application($baseDir);
    $registry = new ExtensionRegistry($app);

    $discovered = $registry->discover();

    expect($discovered)->toHaveCount(3)
        ->toHaveKeys(['ognivo/local-billing', 'acme/dns-sync', 'acme/installed-json-ext'])
        ->and($registry->all())->toHaveCount(3);

    $local = $registry->get('ognivo/local-billing');
    expect($local)->toBeInstanceOf(ExtensionMetadata::class)
        ->and($local?->version)->toBe('1.0.0')
        ->and($local?->description)->toBe('Local billing extension')
        ->and($local?->providers)->toBe([CompatibleExtensionTestProvider::class])
        ->and($registry->get('ognivo/does-not-exist'))->toBeNull();
});

it('gracefully handles missing directories, malformed JSON, and invalid manifests', function (): void {
    $baseDir = createTempBaseDir();
    $this->tempDirs[] = $baseDir;

    $app = new Application($baseDir);
    $registry = new ExtensionRegistry($app);

    // Neither extensions/ nor vendor/ exists yet
    expect($registry->discover())->toBe([])
        ->and($registry->all())->toBe([])
        ->and($registry->get('any/ext'))->toBeNull();

    // Add malformed JSON, invalid manifest, malformed installed.json, and one valid manifest
    mkdir($baseDir.'/extensions/broken-json', 0777, true);
    file_put_contents($baseDir.'/extensions/broken-json/ognivo-extension.json', '{not valid json');

    writeExtensionManifest($baseDir.'/extensions/invalid-manifest', [
        'name' => 'ognivo/invalid-manifest',
        // missing version and ognivo_version_constraint
    ]);

    mkdir($baseDir.'/vendor/composer', 0777, true);
    file_put_contents($baseDir.'/vendor/composer/installed.json', '{invalid');

    writeExtensionManifest($baseDir.'/extensions/valid-ext', [
        'name' => 'ognivo/valid-ext',
        'version' => '1.1.0',
        'ognivo_version_constraint' => '^1.0',
    ]);

    $discovered = $registry->discover();

    expect($discovered)->toHaveCount(1)
        ->toHaveKey('ognivo/valid-ext');
});

it('validates compatibility against configured app.version or default 1.0.0', function (): void {
    $baseDir = createTempBaseDir();
    $this->tempDirs[] = $baseDir;

    writeExtensionManifest($baseDir.'/extensions/compat-ext', [
        'name' => 'ognivo/compat-ext',
        'version' => '1.0.0',
        'ognivo_version_constraint' => '^1.0',
    ]);

    writeExtensionManifest($baseDir.'/extensions/future-ext', [
        'name' => 'ognivo/future-ext',
        'version' => '2.0.0',
        'ognivo_version_constraint' => '^2.0',
    ]);

    // Default core version is 1.0.0 when config is not bound
    $defaultApp = new Application($baseDir);
    $defaultRegistry = new ExtensionRegistry($defaultApp);
    $defaultRegistry->discover();

    $compat = $defaultRegistry->get('ognivo/compat-ext');
    $future = $defaultRegistry->get('ognivo/future-ext');

    expect($compat)->not->toBeNull()
        ->and($future)->not->toBeNull()
        ->and($defaultRegistry->isCompatible($compat))->toBeTrue()
        ->and($defaultRegistry->isCompatible($future))->toBeFalse();

    // Configured core version 2.1.0 flips compatibility
    $configuredApp = new Application($baseDir);
    $configuredApp->instance('config', new ConfigRepository([
        'app' => ['version' => '2.1.0'],
    ]));
    $configuredRegistry = new ExtensionRegistry($configuredApp);
    $configuredRegistry->discover();

    $compatConfigured = $configuredRegistry->get('ognivo/compat-ext');
    $futureConfigured = $configuredRegistry->get('ognivo/future-ext');

    expect($compatConfigured)->not->toBeNull()
        ->and($futureConfigured)->not->toBeNull()
        ->and($configuredRegistry->isCompatible($compatConfigured))->toBeFalse()
        ->and($configuredRegistry->isCompatible($futureConfigured))->toBeTrue();
});

it('registers service providers only for compatible extensions and avoids duplicate registration', function (): void {
    $baseDir = createTempBaseDir();
    $this->tempDirs[] = $baseDir;

    writeExtensionManifest($baseDir.'/extensions/compatible-ext', [
        'name' => 'ognivo/compatible-ext',
        'version' => '1.0.0',
        'ognivo_version_constraint' => '^1.0',
        'providers' => [CompatibleExtensionTestProvider::class],
    ]);

    writeExtensionManifest($baseDir.'/extensions/incompatible-ext', [
        'name' => 'ognivo/incompatible-ext',
        'version' => '2.0.0',
        'ognivo_version_constraint' => '^2.0',
        'providers' => [IncompatibleExtensionTestProvider::class],
    ]);

    $app = new Application($baseDir);
    $registry = new ExtensionRegistry($app);

    $registry->registerProviders();
    // Calling registerProviders() again should not register the same provider twice
    $registry->registerProviders();

    expect($app->bound('test.compatible.extension'))->toBeTrue()
        ->and($app->make('test.compatible.extension'))->toBe('registered')
        ->and($app->getProviders(CompatibleExtensionTestProvider::class))->toHaveCount(1)
        ->and(CompatibleExtensionTestProvider::$registerCount)->toBe(1)
        ->and($app->bound('test.incompatible.extension'))->toBeFalse()
        ->and($app->getProviders(IncompatibleExtensionTestProvider::class))->toBe([])
        ->and(IncompatibleExtensionTestProvider::$registerCount)->toBe(0);
});
