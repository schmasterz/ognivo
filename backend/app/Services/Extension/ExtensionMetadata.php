<?php

declare(strict_types=1);

namespace App\Services\Extension;

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use InvalidArgumentException;
use JsonException;
use UnexpectedValueException;

/**
 * Immutable metadata DTO parsed from an extension's ognivo-extension.json manifest.
 *
 * @phpstan-type ExtensionManifestArray array{
 *     name?: mixed,
 *     version?: mixed,
 *     description?: mixed,
 *     author?: mixed,
 *     ognivo_version_constraint?: mixed,
 *     providers?: mixed,
 *     event_subscribers?: mixed,
 *     eventSubscribers?: mixed,
 * }
 */
final readonly class ExtensionMetadata
{
    /**
     * @param  list<string>  $providers
     * @param  list<string>  $eventSubscribers
     */
    public function __construct(
        public string $name,
        public string $version,
        public string $description,
        public string $author,
        public string $ognivoVersionConstraint,
        public array $providers = [],
        public array $eventSubscribers = [],
    ) {}

    /**
     * Create an ExtensionMetadata instance from a decoded manifest array.
     *
     * @param  ExtensionManifestArray|array<array-key, mixed>  $data
     *
     * @throws InvalidArgumentException When required fields are missing or invalid.
     */
    public static function fromArray(array $data): self
    {
        $name = self::requireNonEmptyString($data, 'name');
        $version = self::requireNonEmptyString($data, 'version');
        $ognivoVersionConstraint = self::requireNonEmptyString($data, 'ognivo_version_constraint');

        $versionParser = new VersionParser;

        try {
            $versionParser->normalize($version);
        } catch (UnexpectedValueException $e) {
            throw new InvalidArgumentException(
                sprintf('Invalid extension version "%s": %s', $version, $e->getMessage()),
                0,
                $e
            );
        }

        try {
            $versionParser->parseConstraints($ognivoVersionConstraint);
        } catch (UnexpectedValueException $e) {
            throw new InvalidArgumentException(
                sprintf('Invalid ognivo_version_constraint "%s": %s', $ognivoVersionConstraint, $e->getMessage()),
                0,
                $e
            );
        }

        $description = self::optionalString($data, 'description');
        $author = self::optionalString($data, 'author');
        $providers = self::optionalStringList($data, 'providers');

        $eventSubscribersKey = array_key_exists('event_subscribers', $data)
            ? 'event_subscribers'
            : 'eventSubscribers';
        $eventSubscribers = self::optionalStringList($data, $eventSubscribersKey);

        return new self(
            name: $name,
            version: $version,
            description: $description,
            author: $author,
            ognivoVersionConstraint: $ognivoVersionConstraint,
            providers: $providers,
            eventSubscribers: $eventSubscribers,
        );
    }

    /**
     * Parse an ognivo-extension.json string into an ExtensionMetadata instance.
     *
     * @throws InvalidArgumentException When the JSON is malformed or manifest validation fails.
     */
    public static function fromJson(string $json): self
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException(
                'Invalid extension manifest JSON: '.$e->getMessage(),
                0,
                $e
            );
        }

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Extension manifest JSON must decode to an object.');
        }

        return self::fromArray($decoded);
    }

    /**
     * Determine whether this extension is compatible with the given Ognivo core version.
     */
    public function isCompatibleWith(string $coreVersion): bool
    {
        return Semver::satisfies($coreVersion, $this->ognivoVersionConstraint);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function requireNonEmptyString(array $data, string $field): string
    {
        if (! array_key_exists($field, $data)) {
            throw new InvalidArgumentException(sprintf('Missing required extension manifest field "%s".', $field));
        }

        $value = $data[$field];

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(
                sprintf('Extension manifest field "%s" must be a non-empty string.', $field)
            );
        }

        return trim($value);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function optionalString(array $data, string $field): string
    {
        if (! array_key_exists($field, $data) || $data[$field] === null) {
            return '';
        }

        $value = $data[$field];

        if (! is_string($value)) {
            throw new InvalidArgumentException(
                sprintf('Extension manifest field "%s" must be a string.', $field)
            );
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<string>
     */
    private static function optionalStringList(array $data, string $field): array
    {
        if (! array_key_exists($field, $data) || $data[$field] === null) {
            return [];
        }

        $value = $data[$field];

        if (! is_array($value)) {
            throw new InvalidArgumentException(
                sprintf('Extension manifest field "%s" must be an array of strings.', $field)
            );
        }

        $result = [];
        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new InvalidArgumentException(
                    sprintf('Every entry in extension manifest field "%s" must be a non-empty string.', $field)
                );
            }

            $result[] = trim($item);
        }

        return $result;
    }
}
