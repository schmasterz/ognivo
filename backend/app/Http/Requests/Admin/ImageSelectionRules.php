<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\DTO\ImageSelectionDTO;
use App\Models\Image;
use Closure;
use Illuminate\Support\Fluent;
use Illuminate\Validation\Rule;

/**
 * Reusable validation rules for image attachment selection and auto-attach flags.
 */
final class ImageSelectionRules
{
    /**
     * Get image selection validation rules.
     *
     * @return array<string, mixed>
     */
    public static function rules(string $prefix = 'images', bool $required = false, bool $includeAutoAttach = true): array
    {
        $hasPrefix = $prefix !== '';
        $p = $hasPrefix ? "{$prefix}." : '';

        $rules = [];

        if ($includeAutoAttach) {
            $rules['auto_attach_images'] = ['nullable', 'boolean'];
        }

        if ($hasPrefix) {
            $rules[$prefix] = [$required ? 'required' : 'nullable', 'array:mode,ids,filter,exclude_ids,filter_ops,attach_ids,detach_ids'];
        }

        $rules["{$p}mode"] = [$required ? 'required' : ($hasPrefix ? "required_with:{$prefix}" : 'nullable'), 'string', Rule::in(ImageSelectionDTO::MODES)];

        $rules["{$p}ids"] = [
            Rule::when(
                fn (Fluent $input): bool => data_get($input, "{$p}mode") === ImageSelectionDTO::MODE_SELECTED,
                ['required', 'array', 'min:1', 'max:500'],
                ['nullable', 'array', 'max:500'],
            ),
            self::validateImageIdsExistRule(),
        ];
        $rules["{$p}ids.*"] = ['integer', 'distinct', 'min:1'];

        $rules["{$p}filter"] = ['nullable', 'array:search,os_type,protocol,enabled'];
        $rules["{$p}filter.search"] = ['nullable', 'string', 'max:255'];
        $rules["{$p}filter.os_type"] = ['nullable', 'string', 'max:64'];
        $rules["{$p}filter.protocol"] = ['nullable', 'string', 'max:64'];
        $rules["{$p}filter.enabled"] = ['nullable', 'boolean'];

        $rules["{$p}exclude_ids"] = ['nullable', 'array', 'max:500', self::validateImageIdsExistRule()];
        $rules["{$p}exclude_ids.*"] = ['integer', 'min:1'];

        $rules["{$p}filter_ops"] = ['nullable', 'array', 'max:10'];
        $rules["{$p}filter_ops.*"] = ['array:action,filter'];
        $rules["{$p}filter_ops.*.action"] = ['required', 'string', Rule::in(['attach', 'detach'])];
        $rules["{$p}filter_ops.*.filter"] = ['nullable', 'array:search,os_type,protocol,enabled'];
        $rules["{$p}filter_ops.*.filter.search"] = ['nullable', 'string', 'max:255'];
        $rules["{$p}filter_ops.*.filter.os_type"] = ['nullable', 'string', 'max:64'];
        $rules["{$p}filter_ops.*.filter.protocol"] = ['nullable', 'string', 'max:64'];
        $rules["{$p}filter_ops.*.filter.enabled"] = ['nullable', 'boolean'];

        $rules["{$p}attach_ids"] = ['nullable', 'array', 'max:500', self::validateImageIdsExistRule()];
        $rules["{$p}attach_ids.*"] = ['integer', 'min:1'];

        $rules["{$p}detach_ids"] = ['nullable', 'array', 'max:500', self::validateImageIdsExistRule()];
        $rules["{$p}detach_ids.*"] = ['integer', 'min:1'];

        return $rules;
    }

    /**
     * Get rules for host creation or update requests.
     *
     * @return array<string, mixed>
     */
    public static function forHostRequest(string $prefix = 'images'): array
    {
        return self::rules(prefix: $prefix, required: false, includeAutoAttach: true);
    }

    /**
     * Get rules for image selection preview requests.
     *
     * @return array<string, mixed>
     */
    public static function forPreviewRequest(string $prefix = 'images'): array
    {
        return self::rules(prefix: $prefix, required: true, includeAutoAttach: false);
    }

    /**
     * Single-query validator rule checking that all provided image IDs exist in the database.
     *
     * @return Closure(string, mixed, Closure(string): void): void
     */
    public static function validateImageIdsExistRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value) || empty($value)) {
                return;
            }

            $ids = [];
            foreach ($value as $item) {
                if (! is_int($item) && (! is_string($item) || ! ctype_digit(ltrim($item, '+')))) {
                    // Let format/type rules report format violations
                    return;
                }

                $intId = (int) $item;
                if ($intId <= 0) {
                    return;
                }

                $ids[$intId] = $intId;
            }

            $uniqueIds = array_values($ids);

            $count = Image::query()->whereIn('id', $uniqueIds)->count();
            if ($count !== count($uniqueIds)) {
                $fail("One or more images in {$attribute} do not exist.");
            }
        };
    }
}
