<?php

declare(strict_types=1);

namespace App\Http\Requests\Vps;

use App\Models\User;
use App\Models\VirtualMachine;
use App\Models\VpsPlanVersion;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * Form Request validating VPS plan-change preview payloads.
 */
final class PlanChangePreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return self::authorizePlanChangeRequest($this);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        /** @var User|null $user */
        $user = $this->user();
        $isAdmin = $user !== null && $user->hasRole('admin');

        return self::sharedRules($isAdmin);
    }

    public function withValidator(Validator $validator): void
    {
        self::applySharedValidator($this, $validator);
    }

    /**
     * Authorize a plan-change preview or store request while rejecting service tokens and leaking 404 for non-owners.
     */
    public static function authorizePlanChangeRequest(FormRequest $request): bool
    {
        if ($request->attributes->get('service_token') !== null) {
            return false;
        }

        if (str_starts_with($request->bearerToken() ?? '', 'srv_')) {
            return false;
        }

        /** @var User|null $user */
        $user = $request->user();
        if ($user === null) {
            return false;
        }

        $vm = $request->route('vm');
        if ($vm instanceof VirtualMachine && ! $user->hasRole('admin') && $vm->user_id !== $user->id) {
            throw (new ModelNotFoundException)->setModel(VirtualMachine::class, [$vm->id]);
        }

        return $user->can('vps.plan_change.request')
            && (! $vm instanceof VirtualMachine || $user->can('changePlan', $vm));
    }

    /**
     * Shared validation rules for plan-change preview and submission requests.
     *
     * @return array<string, list<mixed>>
     */
    public static function sharedRules(bool $isAdmin): array
    {
        if ($isAdmin) {
            return [
                'vps_plan_id' => ['required_without:vps_plan_version_id', 'nullable', 'integer', 'exists:vps_plans,id'],
                'vps_plan_version_id' => ['required_without:vps_plan_id', 'nullable', 'integer', 'exists:vps_plan_versions,id'],
            ];
        }

        return [
            'vps_plan_id' => ['required', 'integer', 'exists:vps_plans,id'],
            'vps_plan_version_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * Enforce admin-only `vps_plan_version_id` access and plan/version consistency.
     */
    public static function applySharedValidator(FormRequest $request, Validator $validator): void
    {
        $validator->after(function () use ($request, $validator): void {
            /** @var User|null $user */
            $user = $request->user();
            if ($user === null) {
                return;
            }

            $isAdmin = $user->hasRole('admin');

            if (! $isAdmin) {
                if ($request->input('vps_plan_version_id') !== null) {
                    $errors = [
                        'vps_plan_version_id' => ['Selecting a specific VPS plan version is restricted to administrators.'],
                    ];

                    $exception = ValidationException::withMessages($errors);
                    $exception->response = response()->json([
                        'message' => 'Selecting a specific VPS plan version is restricted to administrators.',
                        'code' => 'RESOURCE_OVERRIDE_NOT_ALLOWED',
                        'errors' => $errors,
                    ], 422);

                    throw $exception;
                }

                return;
            }

            if ($request->filled('vps_plan_id') && $request->filled('vps_plan_version_id')) {
                $planId = $request->integer('vps_plan_id');
                $versionId = $request->integer('vps_plan_version_id');

                $matches = VpsPlanVersion::query()
                    ->where('id', $versionId)
                    ->where('vps_plan_id', $planId)
                    ->exists();

                if (! $matches) {
                    $validator->errors()->add(
                        'vps_plan_version_id',
                        'The selected VPS plan version does not belong to the specified VPS plan.'
                    );
                }
            }
        });
    }
}
