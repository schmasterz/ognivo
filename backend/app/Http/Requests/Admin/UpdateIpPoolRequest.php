<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\IpPool;
use App\Services\IpManagementService;
use App\Services\Network\CidrHelper;
use App\Services\Network\IpPoolConfigValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Form Request validating IP subnet pool updates.
 */
final class UpdateIpPoolRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'dns_servers' => ['nullable', 'array'],
            'dns_servers.*' => ['ip'],
            'excluded_ips' => ['nullable', 'array'],
            'excluded_ips.*' => ['ip'],
            'cooldown_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'active' => ['nullable', 'boolean'],
            'is_public' => ['nullable', 'boolean'],
            'gateway' => ['nullable', 'ip'],
            'first_usable' => ['nullable', 'ip'],
            'last_usable' => ['nullable', 'ip'],
            'cidr' => ['nullable', 'string', 'max:64'],
            'host_id' => ['nullable', 'integer', 'exists:hosts,id'],
            'network_mode' => ['nullable', 'string', Rule::in(['routed', 'bridged', 'macvlan', 'manual'])],
            'parent_interface' => ['nullable', 'string', 'max:32'],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            /** @var IpPool|null $pool */
            $pool = $this->route('ipPool');
            if (! $pool instanceof IpPool) {
                return;
            }

            $hasAllocations = $pool->allocations()->count() > 0;

            // Enforce immutability of cidr, host_id, network_mode, and L2 gateway when allocations exist
            if ($hasAllocations) {
                if ($this->has('cidr') && $this->input('cidr') !== null && $this->input('cidr') !== $pool->cidr) {
                    $v->errors()->add('cidr', 'CIDR cannot be modified when allocations exist in the pool.');
                }
                if ($this->has('host_id') && $this->input('host_id') !== null && (int) $this->input('host_id') !== $pool->host_id) {
                    $v->errors()->add('host_id', 'Host node cannot be modified when allocations exist in the pool.');
                }
                if ($this->has('network_mode') && $this->input('network_mode') !== null && $this->input('network_mode') !== $pool->network_mode) {
                    $v->errors()->add('network_mode', 'Network mode cannot be modified when allocations exist in the pool.');
                }
                if ($this->has('gateway') && $this->input('gateway') !== $pool->gateway && ($pool->network_mode === 'bridged' || $pool->network_mode === 'macvlan')) {
                    $v->errors()->add('gateway', 'Gateway cannot be modified when allocations exist in bridged or macvlan pools.');
                }
            }

            $inputMode = $this->input('network_mode');
            $effectiveMode = is_string($inputMode) && $inputMode !== '' ? $inputMode : $pool->network_mode;
            $resultingInterface = $this->exists('parent_interface')
                ? $this->input('parent_interface')
                : $pool->parent_interface;

            if ($effectiveMode !== 'manual' && trim(is_string($resultingInterface) ? $resultingInterface : '') === '') {
                $v->errors()->add('parent_interface', 'Parent interface is required when network mode is not manual.');
            }

            $effectiveCidr = (string) ($this->input('cidr') ?: $pool->cidr);
            $rawGateway = $this->input('gateway');
            $effectiveGateway = $this->has('gateway')
                ? (is_string($rawGateway) && trim($rawGateway) !== '' ? $rawGateway : null)
                : $pool->gateway;
            $effectiveExcluded = $this->has('excluded_ips')
                ? (is_array($this->input('excluded_ips')) ? $this->input('excluded_ips') : [])
                : ($pool->excluded_ips ?? []);
            $effectiveFirst = (string) ($this->input('first_usable') ?: $pool->first_usable);
            $effectiveLast = (string) ($this->input('last_usable') ?: $pool->last_usable);

            if (in_array($effectiveMode, ['routed', 'bridged', 'macvlan', 'manual'], true)) {
                $errors = IpPoolConfigValidator::validate(
                    $effectiveMode,
                    $effectiveCidr,
                    $effectiveGateway,
                    $effectiveExcluded,
                    $effectiveFirst,
                    $effectiveLast
                );
                foreach ($errors as $field => $messages) {
                    foreach ($messages as $msg) {
                        $v->errors()->add($field, $msg);
                    }
                }
            }

            // Verify that no active or cooling allocation falls outside the new range
            $firstLong = CidrHelper::ipToUnsigned($effectiveFirst);
            $lastLong = CidrHelper::ipToUnsigned($effectiveLast);

            if ($firstLong !== null && $lastLong !== null) {
                $allocations = $pool->allocations()
                    ->whereIn('status', ['allocated', 'cooling'])
                    ->get(['ip_string']);

                foreach ($allocations as $alloc) {
                    $allocLong = CidrHelper::ipToUnsigned($alloc->ip_string);
                    if ($allocLong !== null && ($allocLong < $firstLong || $allocLong > $lastLong)) {
                        $v->errors()->add('first_usable', sprintf(
                            'Cannot adjust IP range: existing allocation %s falls outside the new range (%s - %s).',
                            $alloc->ip_string,
                            $effectiveFirst,
                            $effectiveLast
                        ));
                        break;
                    }
                }
            }

            // Overlap check
            $effectiveHostId = (int) ($this->input('host_id') ?: $pool->host_id);
            $effectiveIsPublic = $this->has('is_public') ? (bool) $this->input('is_public') : $pool->is_public;

            $conflictingPool = app(IpManagementService::class)->checkPoolOverlap($effectiveCidr, $effectiveHostId, $effectiveIsPublic, $pool->id);
            if ($conflictingPool !== null) {
                $v->errors()->add('cidr', sprintf(
                    "Subnet '%s' overlaps with existing pool '%s' (ID #%d, CIDR %s).",
                    $effectiveCidr,
                    $conflictingPool->name,
                    $conflictingPool->id,
                    $conflictingPool->cidr
                ));
            }
        });
    }
}
