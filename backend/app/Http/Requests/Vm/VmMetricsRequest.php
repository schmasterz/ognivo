<?php

declare(strict_types=1);

namespace App\Http\Requests\Vm;

use App\DTO\Cast;
use App\Services\Metrics\VmMetricsConfigService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ConcreteValidator;

/**
 * Request validator for GET /api/vms/{virtualMachine}/metrics (FR-8).
 */
final class VmMetricsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'range' => ['sometimes', 'string', Rule::in(VmMetricsConfigService::SUPPORTED_RANGES)],
            'metrics' => ['sometimes', 'array', 'min:1'],
            'metrics.*' => ['required', 'string', Rule::in(VmMetricsConfigService::SUPPORTED_METRICS)],
            'max_points' => ['sometimes', 'integer', 'min:10', 'max:'.VmMetricsConfigService::MAX_POINTS_CAP],
        ];
    }

    /**
     * Validate and normalize query parameters for an already-authorized request.
     *
     * @return array{range: string, metrics: list<string>, max_points: int}
     *
     * @throws ValidationException
     */
    public static function validateQuery(Request $request, VmMetricsConfigService $config): array
    {
        /** @var array<string, mixed> $input */
        $input = $request->query();

        if (isset($input['metrics']) && is_string($input['metrics'])) {
            $parts = array_values(array_filter(array_map('trim', explode(',', $input['metrics'])), static fn (string $s): bool => $s !== ''));
            $input['metrics'] = $parts;
        }

        $validator = Validator::make($input, [
            'range' => ['sometimes', 'string', Rule::in(VmMetricsConfigService::SUPPORTED_RANGES)],
            'metrics' => ['sometimes', 'array', 'min:1'],
            'metrics.*' => ['required', 'string', Rule::in(VmMetricsConfigService::SUPPORTED_METRICS)],
            'max_points' => ['sometimes', 'integer', 'min:10', 'max:'.VmMetricsConfigService::MAX_POINTS_CAP],
        ]);

        $validator->after(static function (ConcreteValidator $v) use ($input, $config): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            $requestedRange = isset($input['range']) && is_string($input['range']) ? $input['range'] : '1h';
            if (! $config->isRangeAvailable($requestedRange)) {
                $v->errors()->add('range', 'The requested time range exceeds the configured metrics retention period.');
            }
        });

        /** @var array<string, mixed> $validated */
        $validated = $validator->validate();

        $range = isset($validated['range']) && is_string($validated['range']) ? $validated['range'] : '1h';
        $maxPoints = isset($validated['max_points']) ? Cast::int($validated['max_points'], VmMetricsConfigService::MAX_POINTS_CAP) : VmMetricsConfigService::MAX_POINTS_CAP;

        $rawMetrics = isset($validated['metrics']) && is_array($validated['metrics'])
            ? $validated['metrics']
            : VmMetricsConfigService::SUPPORTED_METRICS;

        /** @var list<string> $metrics */
        $metrics = [];
        foreach ($rawMetrics as $m) {
            if (is_string($m) && in_array($m, VmMetricsConfigService::SUPPORTED_METRICS, true) && ! in_array($m, $metrics, true)) {
                $metrics[] = $m;
            }
        }
        sort($metrics);

        return [
            'range' => $range,
            'metrics' => $metrics,
            'max_points' => $maxPoints,
        ];
    }
}
