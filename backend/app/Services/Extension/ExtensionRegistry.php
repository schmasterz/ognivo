<?php

declare(strict_types=1);

namespace App\Services\Extension;

use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use JsonException;

/**
 * Discovers Ognivo extensions, validates core version compatibility, and registers extension providers.
 */
final class ExtensionRegistry
{
    private const MANIFEST_FILENAME = 'ognivo-extension.json';

    private const DEFAULT_CORE_VERSION = '1.0.0';

    /**
     * @var array<string, ExtensionMetadata>
     */
    private array $extensions = [];

    private bool $discovered = false;

    /**
     * @var array<string, bool>
     */
    private array $registeredProviders = [];

    public function __construct(
        private readonly Application $app,
    ) {}

    /**
     * Scan local extensions and vendor packages for ognivo-extension.json manifests.
     *
     * @return array<string, ExtensionMetadata>
     */
    public function discover(): array
    {
        $discovered = [];
        $seenFiles = [];

        foreach ($this->collectManifestPaths() as $manifestPath) {
            $canonicalPath = realpath($manifestPath);
            $dedupeKey = $canonicalPath !== false ? $canonicalPath : $manifestPath;

            if (isset($seenFiles[$dedupeKey])) {
                continue;
            }
            $seenFiles[$dedupeKey] = true;

            $metadata = $this->loadManifest($manifestPath);
            if ($metadata === null) {
                continue;
            }

            $discovered[$metadata->name] = $metadata;
        }

        $this->extensions = $discovered;
        $this->discovered = true;

        return $this->extensions;
    }

    /**
     * Retrieve a discovered extension's metadata by name, or null if not found.
     */
    public function get(string $name): ?ExtensionMetadata
    {
        if (! $this->discovered) {
            $this->discover();
        }

        return $this->extensions[$name] ?? null;
    }

    /**
     * Return all discovered extension metadata entries keyed by extension name.
     *
     * @return array<string, ExtensionMetadata>
     */
    public function all(): array
    {
        if (! $this->discovered) {
            $this->discover();
        }

        return $this->extensions;
    }

    /**
     * Register service providers for all discovered compatible extensions into the application container.
     */
    public function registerProviders(): void
    {
        if (! $this->discovered) {
            $this->discover();
        }

        foreach ($this->extensions as $metadata) {
            if (! $this->isCompatible($metadata)) {
                continue;
            }

            foreach ($metadata->providers as $providerClass) {
                if (isset($this->registeredProviders[$providerClass])) {
                    continue;
                }

                $this->app->register($providerClass);
                $this->registeredProviders[$providerClass] = true;
            }
        }
    }

    /**
     * Determine whether the given extension is compatible with the current Ognivo core version.
     */
    public function isCompatible(ExtensionMetadata $metadata): bool
    {
        return $metadata->isCompatibleWith($this->resolveCoreVersion());
    }

    /**
     * Collect candidate ognivo-extension.json paths from vendor packages and local extensions directory.
     *
     * @return list<string>
     */
    private function collectManifestPaths(): array
    {
        $paths = [];

        $vendorDir = $this->app->basePath('vendor');
        if (is_dir($vendorDir)) {
            $paths = [
                ...$this->collectVendorInstalledPaths($vendorDir),
                ...$this->globManifests(
                    $vendorDir.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.self::MANIFEST_FILENAME
                ),
            ];
        }

        $candidateExtensionDirs = array_values(array_unique([
            $this->app->basePath('extensions'),
            dirname($this->app->basePath()).DIRECTORY_SEPARATOR.'extensions',
        ]));

        foreach ($candidateExtensionDirs as $extensionsDir) {
            if (is_dir($extensionsDir)) {
                $paths = [
                    ...$paths,
                    ...$this->globManifests(
                        $extensionsDir.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.self::MANIFEST_FILENAME
                    ),
                ];
            }
        }

        return $paths;
    }

    /**
     * Collect manifest paths from Composer's vendor/composer/installed.json metadata.
     *
     * @return list<string>
     */
    private function collectVendorInstalledPaths(string $vendorDir): array
    {
        $composerDir = $vendorDir.DIRECTORY_SEPARATOR.'composer';
        $installedJsonPath = $composerDir.DIRECTORY_SEPARATOR.'installed.json';

        if (! is_file($installedJsonPath) || ! is_readable($installedJsonPath)) {
            return [];
        }

        $contents = file_get_contents($installedJsonPath);
        if ($contents === false) {
            return [];
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        $packages = array_key_exists('packages', $decoded) && is_array($decoded['packages'])
            ? $decoded['packages']
            : $decoded;

        $paths = [];
        foreach ($packages as $package) {
            if (! is_array($package)) {
                continue;
            }

            $installPath = $package['install-path'] ?? null;
            if (! is_string($installPath) || trim($installPath) === '') {
                continue;
            }

            $manifestPath = $composerDir
                .DIRECTORY_SEPARATOR
                .trim($installPath)
                .DIRECTORY_SEPARATOR
                .self::MANIFEST_FILENAME;

            if (is_file($manifestPath)) {
                $paths[] = $manifestPath;
            }
        }

        return $paths;
    }

    /**
     * @return list<string>
     */
    private function globManifests(string $pattern): array
    {
        $matches = glob($pattern, GLOB_NOSORT);
        if ($matches === false) {
            return [];
        }

        $paths = [];
        foreach ($matches as $match) {
            if (is_file($match)) {
                $paths[] = $match;
            }
        }

        return $paths;
    }

    private function loadManifest(string $manifestPath): ?ExtensionMetadata
    {
        if (! is_file($manifestPath) || ! is_readable($manifestPath)) {
            return null;
        }

        $contents = file_get_contents($manifestPath);
        if ($contents === false) {
            return null;
        }

        try {
            return ExtensionMetadata::fromJson($contents);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function resolveCoreVersion(): string
    {
        if ($this->app->bound('config')) {
            $config = $this->app->make('config');
            $configuredVersion = $config->get('app.version');

            if (is_string($configuredVersion) && trim($configuredVersion) !== '') {
                return trim($configuredVersion);
            }
        }

        return self::DEFAULT_CORE_VERSION;
    }
}
