<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Extension\ExtensionRegistry;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

/**
 * Create a temporary base directory for isolated extension manifest discovery.
 */
function createTempExtensionBaseDir(): string
{
    $dir = sys_get_temp_dir().'/ognivo-ext-api-'.bin2hex(random_bytes(8));
    mkdir($dir, 0777, true);

    return $dir;
}

/**
 * Recursively delete a temporary directory tree created during testing.
 */
function deleteExtensionTempDir(string $dir): void
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
            deleteExtensionTempDir($path);
        } else {
            unlink($path);
        }
    }

    rmdir($dir);
}

/**
 * Write an ognivo-extension.json manifest inside the given directory.
 *
 * @param  array<string, mixed>  $manifest
 */
function writeApiTestExtensionManifest(string $dir, array $manifest): void
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

    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

    $this->admin = User::factory()->create([
        'name' => 'Extension Admin',
        'email' => 'ext-admin@ognivo.local',
        'status' => 'active',
    ]);
    $this->admin->assignRole('admin');

    $this->customer = User::factory()->create([
        'name' => 'Regular Customer',
        'email' => 'ext-customer@ognivo.local',
        'status' => 'active',
    ]);
    $this->customer->assignRole('customer');

    $baseDir = createTempExtensionBaseDir();
    $this->tempDirs[] = $baseDir;

    writeApiTestExtensionManifest($baseDir.'/extensions/local-billing', [
        'name' => 'ognivo/local-billing',
        'version' => '1.2.0',
        'description' => 'Local billing integration',
        'author' => 'Ognivo Team',
        'ognivo_version_constraint' => '^1.0',
        'providers' => ['Ognivo\\LocalBilling\\BillingServiceProvider'],
        'event_subscribers' => ['Ognivo\\LocalBilling\\BillingEventSubscriber'],
    ]);

    writeApiTestExtensionManifest($baseDir.'/extensions/future-dns', [
        'name' => 'acme/future-dns',
        'version' => '2.0.0',
        'description' => 'Future DNS sync',
        'author' => 'Acme Corp',
        'ognivo_version_constraint' => '^2.0',
        'providers' => ['Acme\\FutureDns\\DnsServiceProvider'],
        'event_subscribers' => [],
    ]);

    $isolatedApp = Mockery::mock(ApplicationContract::class);
    $isolatedApp->shouldReceive('basePath')
        ->andReturnUsing(static fn (string $path = ''): string => $path === '' ? $baseDir : $baseDir.DIRECTORY_SEPARATOR.$path);
    $isolatedApp->shouldReceive('bound')
        ->with('config')
        ->andReturn(true);
    $isolatedApp->shouldReceive('make')
        ->with('config')
        ->andReturn(new ConfigRepository([
            'app' => ['version' => '1.5.0'],
        ]));

    $registry = new ExtensionRegistry($isolatedApp);
    $this->app->instance(ExtensionRegistry::class, $registry);
});

afterEach(function (): void {
    foreach ($this->tempDirs as $dir) {
        deleteExtensionTempDir($dir);
    }
});

test('admin can list registered extensions with compatibility status', function (): void {
    Sanctum::actingAs($this->admin);

    $response = $this->getJson('/api/v1/extensions');

    $response->assertOk()
        ->assertJsonCount(2, 'data');

    /** @var list<array<string, mixed>> $data */
    $data = $response->json('data');

    $byName = [];
    foreach ($data as $item) {
        /** @var string $name */
        $name = $item['name'];
        $byName[$name] = $item;
    }

    expect($byName)->toHaveKeys(['ognivo/local-billing', 'acme/future-dns'])
        ->and($byName['ognivo/local-billing'])->toBe([
            'name' => 'ognivo/local-billing',
            'version' => '1.2.0',
            'description' => 'Local billing integration',
            'author' => 'Ognivo Team',
            'ognivo_version_constraint' => '^1.0',
            'providers' => ['Ognivo\\LocalBilling\\BillingServiceProvider'],
            'event_subscribers' => ['Ognivo\\LocalBilling\\BillingEventSubscriber'],
            'compatible' => true,
        ])
        ->and($byName['acme/future-dns'])->toBe([
            'name' => 'acme/future-dns',
            'version' => '2.0.0',
            'description' => 'Future DNS sync',
            'author' => 'Acme Corp',
            'ognivo_version_constraint' => '^2.0',
            'providers' => ['Acme\\FutureDns\\DnsServiceProvider'],
            'event_subscribers' => [],
            'compatible' => false,
        ]);
});

test('admin can inspect a single extension by composer vendor/package name', function (): void {
    Sanctum::actingAs($this->admin);

    $response = $this->getJson('/api/v1/extensions/ognivo/local-billing');

    $response->assertOk()
        ->assertExactJson([
            'data' => [
                'name' => 'ognivo/local-billing',
                'version' => '1.2.0',
                'description' => 'Local billing integration',
                'author' => 'Ognivo Team',
                'ognivo_version_constraint' => '^1.0',
                'providers' => ['Ognivo\\LocalBilling\\BillingServiceProvider'],
                'event_subscribers' => ['Ognivo\\LocalBilling\\BillingEventSubscriber'],
                'compatible' => true,
            ],
        ]);
});

test('inspecting a non-existent extension returns 404', function (): void {
    Sanctum::actingAs($this->admin);

    $this->getJson('/api/v1/extensions/ognivo/does-not-exist')
        ->assertNotFound();
});

test('non-admin user receives 403 on extension endpoints', function (): void {
    Sanctum::actingAs($this->customer);

    $this->getJson('/api/v1/extensions')
        ->assertForbidden();

    $this->getJson('/api/v1/extensions/ognivo/local-billing')
        ->assertForbidden();
});

test('inactive admin user receives 403 on extension endpoints', function (): void {
    $this->admin->update(['status' => 'suspended']);
    Sanctum::actingAs($this->admin);

    $this->getJson('/api/v1/extensions')
        ->assertForbidden();

    $this->getJson('/api/v1/extensions/ognivo/local-billing')
        ->assertForbidden();
});

test('unauthenticated user receives 401 on extension endpoints', function (): void {
    $this->getJson('/api/v1/extensions')
        ->assertUnauthorized();

    $this->getJson('/api/v1/extensions/ognivo/local-billing')
        ->assertUnauthorized();
});
