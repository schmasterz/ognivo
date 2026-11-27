<?php

declare(strict_types=1);

namespace App\ValueObjects\Image;

use App\Support\Cast;
use InvalidArgumentException;

/**
 * Immutable Value Object describing the desired image attachment state of a host node.
 */
final readonly class ImageSelection
{
    public const string MODE_ALL = 'all';

    public const string MODE_SELECTED = 'selected';

    public const string MODE_FILTER = 'filter';

    public const string MODE_DELTA = 'delta';

    /**
     * @var list<string>
     */
    public const array MODES = [
        self::MODE_ALL,
        self::MODE_SELECTED,
        self::MODE_FILTER,
        self::MODE_DELTA,
    ];

    /**
     * @var list<string>
     */
    public const array FILTER_KEYS = [
        'search',
        'os_type',
        'protocol',
        'enabled',
    ];

    /**
     * @var self::MODE_*
     */
    public string $mode;

    /**
     * @var list<int>
     */
    public array $ids;

    /**
     * @var array<string, mixed>
     */
    public array $filter;

    /**
     * @var list<int>
     */
    public array $excludeIds;

    /**
     * @var list<array{action: 'attach'|'detach', filter: array<string, mixed>}>
     */
    public array $filterOps;

    /**
     * @var list<int>
     */
    public array $attachIds;

    /**
     * @var list<int>
     */
    public array $detachIds;

    /**
     * @param  self::MODE_*|string  $mode
     * @param  array<mixed>  $ids
     * @param  array<string, mixed>  $filter
     * @param  array<mixed>  $excludeIds
     * @param  array<mixed>  $filterOps
     * @param  array<mixed>  $attachIds
     * @param  array<mixed>  $detachIds
     */
    public function __construct(
        string $mode,
        array $ids = [],
        array $filter = [],
        array $excludeIds = [],
        array $filterOps = [],
        array $attachIds = [],
        array $detachIds = [],
    ) {
        if (! in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException("Invalid mode: {$mode}");
        }

        /** @var self::MODE_* $mode */
        $this->mode = $mode;
        $this->ids = self::normalizeIds($ids);
        self::validateFilter($filter);
        $this->filter = $filter;
        $this->excludeIds = self::normalizeIds($excludeIds);

        if (count($filterOps) > 10) {
            throw new InvalidArgumentException('Delta mode exceeds maximum of 10 filter operations.');
        }

        $normalizedOps = [];
        foreach ($filterOps as $op) {
            if (! is_array($op)) {
                throw new InvalidArgumentException('Filter op must be an array.');
            }
            $action = $op['action'] ?? null;
            if ($action !== 'attach' && $action !== 'detach') {
                throw new InvalidArgumentException("Invalid filter op action: expected 'attach' or 'detach'.");
            }
            $opFilter = $op['filter'] ?? [];
            if (! is_array($opFilter)) {
                throw new InvalidArgumentException('Filter op filter must be an array.');
            }
            /** @var array<string, mixed> $opFilter */
            self::validateFilter($opFilter);
            $normalizedOps[] = [
                'action' => $action,
                'filter' => $opFilter,
            ];
        }
        $this->filterOps = $normalizedOps;

        $this->attachIds = self::normalizeIds($attachIds);
        $this->detachIds = self::normalizeIds($detachIds);

        if ($this->mode === self::MODE_SELECTED) {
            if (empty($this->ids)) {
                throw new InvalidArgumentException('Selected mode requires at least one image ID.');
            }
            if (count($this->ids) > 500) {
                throw new InvalidArgumentException('Selected mode exceeds maximum of 500 image IDs.');
            }
        }

        if (count($this->excludeIds) > 500) {
            throw new InvalidArgumentException('Exclude IDs exceed maximum of 500.');
        }

        if (count($this->attachIds) > 500) {
            throw new InvalidArgumentException('Attach IDs exceed maximum of 500.');
        }

        if (count($this->detachIds) > 500) {
            throw new InvalidArgumentException('Detach IDs exceed maximum of 500.');
        }
    }

    public static function all(): self
    {
        return new self(self::MODE_ALL);
    }

    /**
     * @param  array<mixed>  $ids
     */
    public static function selected(array $ids): self
    {
        return new self(self::MODE_SELECTED, ids: $ids);
    }

    /**
     * @param  array<string, mixed>  $filter
     * @param  array<mixed>  $excludeIds
     */
    public static function filter(array $filter, array $excludeIds = []): self
    {
        return new self(self::MODE_FILTER, filter: $filter, excludeIds: $excludeIds);
    }

    /**
     * @param  array<mixed>  $filterOps
     * @param  array<mixed>  $attachIds
     * @param  array<mixed>  $detachIds
     */
    public static function delta(array $filterOps = [], array $attachIds = [], array $detachIds = []): self
    {
        return new self(
            self::MODE_DELTA,
            filterOps: $filterOps,
            attachIds: $attachIds,
            detachIds: $detachIds,
        );
    }

    /**
     * Parse from API request payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $mode = Cast::string($payload['mode'] ?? '');

        /** @var array<mixed> $ids */
        $ids = is_array($payload['ids'] ?? null) ? $payload['ids'] : [];

        /** @var array<string, mixed> $filter */
        $filter = is_array($payload['filter'] ?? null) ? $payload['filter'] : [];

        /** @var array<mixed> $excludeIds */
        $excludeIds = is_array($payload['exclude_ids'] ?? null)
            ? $payload['exclude_ids']
            : (is_array($payload['excludeIds'] ?? null) ? $payload['excludeIds'] : []);

        /** @var array<mixed> $filterOps */
        $filterOps = is_array($payload['filter_ops'] ?? null)
            ? $payload['filter_ops']
            : (is_array($payload['filterOps'] ?? null) ? $payload['filterOps'] : []);

        /** @var array<mixed> $attachIds */
        $attachIds = is_array($payload['attach_ids'] ?? null)
            ? $payload['attach_ids']
            : (is_array($payload['attachIds'] ?? null) ? $payload['attachIds'] : []);

        /** @var array<mixed> $detachIds */
        $detachIds = is_array($payload['detach_ids'] ?? null)
            ? $payload['detach_ids']
            : (is_array($payload['detachIds'] ?? null) ? $payload['detachIds'] : []);

        return match ($mode) {
            self::MODE_ALL => self::all(),
            self::MODE_SELECTED => self::selected($ids),
            self::MODE_FILTER => self::filter($filter, $excludeIds),
            self::MODE_DELTA => self::delta($filterOps, $attachIds, $detachIds),
            default => throw new InvalidArgumentException("Invalid mode: {$mode}"),
        };
    }

    /**
     * Determine whether the delta contains no changes.
     */
    public function isNoop(): bool
    {
        return $this->mode === self::MODE_DELTA
            && empty($this->filterOps)
            && empty($this->attachIds)
            && empty($this->detachIds);
    }

    /**
     * Normalize IDs to unique positive integers.
     *
     * @param  array<mixed>  $ids
     * @return list<int>
     */
    private static function normalizeIds(array $ids): array
    {
        $normalized = [];
        foreach ($ids as $id) {
            if (! is_int($id) && (! is_string($id) || ! ctype_digit(ltrim($id, '+')))) {
                throw new InvalidArgumentException('Image IDs must be positive integers.');
            }
            $intId = (int) $id;
            if ($intId <= 0) {
                throw new InvalidArgumentException('Image IDs must be positive integers.');
            }
            $normalized[$intId] = $intId;
        }

        return array_values($normalized);
    }

    /**
     * Validate filter keys against the whitelist and ensure expected value types.
     *
     * @param  array<string, mixed>  $filter
     */
    private static function validateFilter(array $filter): void
    {
        foreach ($filter as $key => $value) {
            if (! in_array($key, self::FILTER_KEYS, true)) {
                throw new InvalidArgumentException("Unknown filter key: {$key}");
            }

            if ($key === 'search' && ! is_string($value)) {
                throw new InvalidArgumentException("Filter 'search' must be a string.");
            }
            if ($key === 'os_type' && ! is_string($value)) {
                throw new InvalidArgumentException("Filter 'os_type' must be a string.");
            }
            if ($key === 'protocol' && ! is_string($value)) {
                throw new InvalidArgumentException("Filter 'protocol' must be a string.");
            }
            if ($key === 'enabled' && ! is_bool($value)) {
                throw new InvalidArgumentException("Filter 'enabled' must be a boolean.");
            }
        }
    }
}
