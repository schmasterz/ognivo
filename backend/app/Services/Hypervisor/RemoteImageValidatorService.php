<?php

declare(strict_types=1);

namespace App\Services\Hypervisor;

use App\ValueObjects\Image\RemoteImageValidationResult;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Validates OS image entries against remote image servers supporting simplestreams and Incus/LXD protocols.
 */
final class RemoteImageValidatorService
{
    private const int CATALOG_CACHE_TTL_SECONDS = 3600;

    private const int HTTP_TIMEOUT_SECONDS = 5;

    /**
     * Validate that an image alias exists on the specified remote image server.
     */
    public function validate(
        string $source,
        string $imageName,
        string $protocol = 'simplestreams',
        ?string $osType = null,
    ): RemoteImageValidationResult {
        $cleanAlias = $this->normalizeAlias($imageName);

        if ($cleanAlias === '') {
            return RemoteImageValidationResult::failure('Image name cannot be empty.');
        }

        $normalizedProtocol = strtolower(trim($protocol));

        if ($normalizedProtocol === 'simplestreams' || $normalizedProtocol === '') {
            return $this->validateSimplestreams($source, $cleanAlias, $osType);
        }

        if ($normalizedProtocol === 'incus' || $normalizedProtocol === 'lxd') {
            return $this->validateIncus($source, $cleanAlias);
        }

        return RemoteImageValidationResult::failure(
            "Unsupported image protocol '{$normalizedProtocol}'. Supported protocols: simplestreams, incus, lxd."
        );
    }

    /**
     * Strip leading CLI remote prefix (e.g. 'images:debian/12' -> 'debian/12').
     */
    private function normalizeAlias(string $imageName): string
    {
        $cleanAlias = trim($imageName);

        if (preg_match('/^[a-z0-9_-]+:([a-z0-9_.-]+\/.+)$/i', $cleanAlias, $matches) === 1) {
            return $matches[1];
        }

        return $cleanAlias;
    }

    private function normalizeSourceUrl(string $source): ?string
    {
        $trimmed = trim($source);

        if (filter_var($trimmed, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return rtrim($trimmed, '/');
    }

    private function validateSimplestreams(
        string $source,
        string $cleanAlias,
        ?string $osType = null,
    ): RemoteImageValidationResult {
        $normalizedSource = $this->normalizeSourceUrl($source);

        if ($normalizedSource === null) {
            return RemoteImageValidationResult::failure("Invalid source URL '{$source}'.");
        }

        $cacheKey = 'simplestreams:catalog:'.md5($normalizedSource);

        try {
            /** @var array<string, mixed> $catalog */
            $catalog = Cache::remember(
                $cacheKey,
                self::CATALOG_CACHE_TTL_SECONDS,
                fn (): array => $this->fetchSimplestreamsCatalog($normalizedSource)
            );
        } catch (Throwable $e) {
            return RemoteImageValidationResult::failure(
                "Failed to connect to remote simplestreams server '{$normalizedSource}': ".$e->getMessage()
            );
        }

        if (! is_array($catalog['products'] ?? null)) {
            return RemoteImageValidationResult::failure(
                "Image alias '{$cleanAlias}' was not found on remote simplestreams server '{$normalizedSource}'."
            );
        }

        $candidateAliases = [
            $cleanAlias,
            $cleanAlias.'/default',
            $cleanAlias.'/cloud',
        ];

        /** @var list<string> $architectures */
        $architectures = [];
        $matched = false;

        foreach ($catalog['products'] as $product) {
            if (! is_array($product)) {
                continue;
            }

            $aliasesRaw = $product['aliases'] ?? null;
            if (! is_string($aliasesRaw) || $aliasesRaw === '') {
                continue;
            }

            $productAliases = array_map('trim', explode(',', $aliasesRaw));

            if (! $this->matchesAnyAlias($candidateAliases, $productAliases)) {
                continue;
            }

            $matched = true;
            $arch = $product['arch'] ?? null;

            if (is_string($arch) && $arch !== '' && ! in_array($arch, $architectures, true)) {
                $architectures[] = $arch;
            }
        }

        if ($matched) {
            return RemoteImageValidationResult::success(
                $cleanAlias,
                $architectures,
                ['source' => $normalizedSource]
            );
        }

        return RemoteImageValidationResult::failure(
            "Image alias '{$cleanAlias}' was not found on remote simplestreams server '{$normalizedSource}'."
        );
    }

    /**
     * Fetch and decode the simplestreams image downloads catalog from the remote server.
     *
     * @return array<string, mixed>
     */
    private function fetchSimplestreamsCatalog(string $source): array
    {
        /** @var Response $indexResponse */
        $indexResponse = Http::timeout(self::HTTP_TIMEOUT_SECONDS)
            ->get($source.'/streams/v1/index.json');

        if (! $indexResponse->successful()) {
            /** @var Response $directResponse */
            $directResponse = Http::timeout(self::HTTP_TIMEOUT_SECONDS)
                ->get($source.'/streams/v1/images.json');

            return $this->decodeCatalogResponse($directResponse);
        }

        $imagesPath = $this->extractImageDownloadsPath($indexResponse->json()) ?? 'streams/v1/images.json';
        /** @var Response $imagesResponse */
        $imagesResponse = Http::timeout(self::HTTP_TIMEOUT_SECONDS)
            ->get($source.'/'.ltrim($imagesPath, '/'));

        return $this->decodeCatalogResponse($imagesResponse);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeCatalogResponse(Response $response): array
    {
        if (! $response->successful()) {
            throw new RuntimeException("HTTP {$response->status()} fetching simplestreams catalog.");
        }

        $decoded = $response->json();
        if (! is_array($decoded)) {
            throw new RuntimeException('Invalid JSON payload from simplestreams images catalog.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function extractImageDownloadsPath(mixed $indexData): ?string
    {
        if (! is_array($indexData) || ! is_array($indexData['index'] ?? null)) {
            return null;
        }

        foreach ($indexData['index'] as $stream) {
            if (! is_array($stream)) {
                continue;
            }

            $datatype = $stream['datatype'] ?? null;
            $path = $stream['path'] ?? null;

            if ($datatype === 'image-downloads' && is_string($path) && $path !== '') {
                return $path;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $candidates
     * @param  list<string>  $productAliases
     */
    private function matchesAnyAlias(array $candidates, array $productAliases): bool
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $productAliases, true)) {
                return true;
            }
        }

        return false;
    }

    private function validateIncus(string $source, string $cleanAlias): RemoteImageValidationResult
    {
        $normalizedSource = $this->normalizeSourceUrl($source);

        if ($normalizedSource === null) {
            return RemoteImageValidationResult::failure("Invalid source URL '{$source}'.");
        }

        try {
            /** @var Response $response */
            $response = Http::timeout(self::HTTP_TIMEOUT_SECONDS)
                ->get($normalizedSource.'/1.0/images/aliases/'.rawurlencode($cleanAlias));
        } catch (Throwable $e) {
            return RemoteImageValidationResult::failure(
                "Failed to connect to remote Incus/LXD server '{$normalizedSource}': ".$e->getMessage()
            );
        }

        if ($response->status() === 200) {
            $target = $response->json('metadata.target');

            if (is_string($target) && $target !== '') {
                return RemoteImageValidationResult::success($cleanAlias, [], ['target' => $target]);
            }
        }

        if ($response->status() === 404) {
            return RemoteImageValidationResult::failure(
                "Image alias '{$cleanAlias}' was not found on remote Incus/LXD server '{$normalizedSource}'."
            );
        }

        return RemoteImageValidationResult::failure(
            'Failed to verify image on remote Incus server: '.$response->body()
        );
    }
}
