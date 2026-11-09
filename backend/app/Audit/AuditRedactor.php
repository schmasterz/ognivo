<?php

declare(strict_types=1);

namespace App\Audit;

use App\DTO\Cast;
use Illuminate\Database\Eloquent\Model;

final class AuditRedactor
{
    /**
     * Keys to remove case-insensitively and recursively.
     *
     * @var array<int, string>
     */
    public const REDACTED_KEYS = [
        'password',
        'password_confirmation',
        'token',
        'trust_token',
        'private_key',
        'server_certificate',
        'smtp_password',
        'secret',
        'authorization',
    ];

    /**
     * Maximum character length for any string value in the context.
     */
    public const MAX_STRING_LENGTH = 500;

    /**
     * Maximum serialized size for context in bytes (8 KB).
     */
    public const MAX_CONTEXT_BYTES = 8192;

    /**
     * Recursively clean, redact secrets, truncate strings, and cap array size.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $additionalHiddenKeys
     * @return array<string, mixed>
     */
    public static function clean(array $data, array $additionalHiddenKeys = []): array
    {
        /** @var array<int, string> $combined */
        $combined = array_values(array_unique(array_merge(self::REDACTED_KEYS, $additionalHiddenKeys)));
        /** @var array<int, string> $normalizedHidden */
        $normalizedHidden = array_map('strtolower', $combined);

        $cleaned = self::cleanRecursive($data, $normalizedHidden);

        return self::capSize($cleaned, self::MAX_CONTEXT_BYTES);
    }

    /**
     * Recursively remove sensitive keys and truncate long strings.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $hiddenKeys
     * @return array<string, mixed>
     */
    public static function cleanRecursive(array $data, array $hiddenKeys): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $keyStr = (string) $key;
            $lowerKey = strtolower($keyStr);

            if (in_array($lowerKey, $hiddenKeys, true)) {
                if ($lowerKey === 'smtp_password' && is_array($value) && isset($value['changed']) && $value['changed'] === true) {
                    $result[$keyStr] = ['changed' => true];
                }

                continue;
            }

            if (is_array($value)) {
                /** @var array<string, mixed> $subArray */
                $subArray = [];
                foreach ($value as $k => $v) {
                    $subArray[Cast::string($k)] = $v;
                }
                $result[$keyStr] = self::cleanRecursive($subArray, $hiddenKeys);
            } elseif (is_string($value)) {
                $result[$keyStr] = mb_strlen($value) > self::MAX_STRING_LENGTH
                    ? mb_substr($value, 0, self::MAX_STRING_LENGTH)
                    : $value;
            } else {
                $result[$keyStr] = $value;
            }
        }

        return $result;
    }

    /**
     * Produce a redacted diff between two states or models.
     *
     * @param  Model|array<string, mixed>|null  $before
     * @param  Model|array<string, mixed>|null  $after
     * @return array<string, mixed>
     */
    public static function diff(Model|array|null $before, Model|array|null $after): array
    {
        /** @var array<string, mixed> $beforeArray */
        $beforeArray = $before instanceof Model ? $before->getAttributes() : ($before ?? []);
        /** @var array<string, mixed> $afterArray */
        $afterArray = $after instanceof Model ? $after->getAttributes() : ($after ?? []);

        /** @var array<int, string> $hiddenKeys */
        $hiddenKeys = [];
        if ($before instanceof Model) {
            $hiddenKeys = array_values(array_map('strval', $before->getHidden()));
        }
        if ($after instanceof Model) {
            $hiddenKeys = array_values(array_unique(array_merge(
                $hiddenKeys,
                array_values(array_map('strval', $after->getHidden()))
            )));
        }

        /** @var array<int, string> $allKeys */
        $allKeys = array_values(array_unique(array_merge(array_keys($beforeArray), array_keys($afterArray))));
        /** @var array<string, mixed> $diff */
        $diff = [];

        foreach ($allKeys as $key) {
            $keyStr = Cast::string($key);
            $oldVal = $beforeArray[$keyStr] ?? null;
            $newVal = $afterArray[$keyStr] ?? null;

            if ($oldVal !== $newVal) {
                $diff[$keyStr] = [
                    'old' => $oldVal,
                    'new' => $newVal,
                ];
            }
        }

        return self::clean($diff, $hiddenKeys);
    }

    /**
     * Cap the serialized context size at max bytes, dropping largest values first and setting _truncated: true.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function capSize(array $data, int $maxBytes = self::MAX_CONTEXT_BYTES): array
    {
        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded !== false && strlen($encoded) <= $maxBytes) {
            return $data;
        }

        $data['_truncated'] = true;

        while (true) {
            $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encoded === false || strlen($encoded) <= $maxBytes) {
                break;
            }

            $largestKey = null;
            $largestSize = -1;

            foreach ($data as $k => $v) {
                if ($k === '_truncated') {
                    continue;
                }

                $size = strlen((string) json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                if ($size > $largestSize) {
                    $largestSize = $size;
                    $largestKey = $k;
                }
            }

            if ($largestKey === null) {
                break;
            }

            // If the largest entry is an associative array with multiple entries, prune the largest entry inside it first
            if (is_array($data[$largestKey]) && count($data[$largestKey]) > 1) {
                $subLargestKey = null;
                $subLargestSize = -1;
                foreach ($data[$largestKey] as $subK => $subV) {
                    $s = strlen((string) json_encode($subV, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                    if ($s > $subLargestSize) {
                        $subLargestSize = $s;
                        $subLargestKey = (string) $subK;
                    }
                }
                /** @psalm-suppress PossiblyNullArrayOffset */
                unset($data[$largestKey][$subLargestKey]);

                continue;
            }

            unset($data[$largestKey]);
        }

        return $data;
    }
}
