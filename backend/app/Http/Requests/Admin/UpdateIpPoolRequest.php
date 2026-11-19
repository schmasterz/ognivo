<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\IpPool;
use App\Services\IpManagementService;
use App\Services\Network\CidrHelper;
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

            // Enforce immutability of cidr, host_id, network_mode when allocations exist
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
            }

            $inputMode = $this->input('network_mode');
            $resultingMode = is_string($inputMode) ? $inputMode : $pool->network_mode;
            $resultingInterface = $this->exists('parent_interface')
                ? $this->input('parent_interface')
                : $pool->parent_interface;

            if ($resultingMode !== 'manual' && trim(is_string($resultingInterface) ? $resultingInterface : '') === '') {
                $v->errors()->add('parent_interface', 'Parent interface is required when network mode is not manual.');
            }

            $cidr = (string) ($this->input('cidr') ?: $pool->cidr);
            $parsed = CidrHelper::parse($cidr);
            if ($parsed === null) {
                $v->errors()->add('cidr', 'CIDR must be in format IPv4/prefix (e.g. 192.168.1.0/24).');

                return;
            }

            [$ip, $prefix] = $parsed;

            if ($prefix < 16 || $prefix > 32) {
                $v->errors()->add('cidr', 'CIDR prefix length must be between 16 and 32.');

                return;
            }

            $range = CidrHelper::subnetRange($cidr);
            if ($range === null) {
                $v->errors()->add('cidr', 'Invalid CIDR subnet range.');

                return;
            }

            [$network, $broadcast] = $range;
            $defaults = CidrHelper::calculateUsableRange($cidr);
            if ($defaults === null) {
                $v->errors()->add('cidr', 'Unable to calculate usable range for CIDR.');

                return;
            }

            $minAllowed = CidrHelper::ipToUnsigned($defaults['first']);
            $maxAllowed = CidrHelper::ipToUnsigned($defaults['last']);

            $firstUsable = (string) ($this->input('first_usable') ?: $pool->first_usable);
            $lastUsable = (string) ($this->input('last_usable') ?: $pool->last_usable);

            $firstLong = CidrHelper::ipToUnsigned($firstUsable);
            $lastLong = CidrHelper::ipToUnsigned($lastUsable);

            if ($firstLong === null || $minAllowed === null || $maxAllowed === null || $firstLong < $minAllowed || $firstLong > $maxAllowed) {
                $v->errors()->add('first_usable', 'First usable IP is outside the CIDR subnet.');
            }

            if ($lastLong === null || $minAllowed === null || $maxAllowed === null || $lastLong < $minAllowed || $lastLong > $maxAllowed) {
                $v->errors()->add('last_usable', 'Last usable IP is outside the CIDR subnet.');
            }

            if ($firstLong !== null && $lastLong !== null && $firstLong > $lastLong) {
                $v->errors()->add('first_usable', 'First usable IP cannot be greater than last usable IP.');
            }

            // Verify that no active or cooling allocation falls outside the new range
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
                            $firstUsable,
                            $lastUsable
                        ));
                        break;
                    }
                }
            }

            // Gateway check
            $gateway = $this->has('gateway') ? $this->input('gateway') : $pool->gateway;
            if ($prefix <= 30 && (empty($gateway) || ! is_string($gateway))) {
                $v->errors()->add('gateway', 'Gateway is required for subnets /16 to /30.');
            }

            if ($gateway && is_string($gateway)) {
                $gwLong = CidrHelper::ipToUnsigned($gateway);
                if ($gwLong === null || $gwLong < $network || $gwLong > $broadcast) {
                    $v->errors()->add('gateway', 'Gateway IP is outside the CIDR subnet.');
                }
            }

            // Overlap check
            $effectiveHostId = (int) ($this->input('host_id') ?: $pool->host_id);
            $effectiveIsPublic = $this->has('is_public') ? (bool) $this->input('is_public') : $pool->is_public;

            $conflictingPool = app(IpManagementService::class)->checkPoolOverlap($cidr, $effectiveHostId, $effectiveIsPublic, $pool->id);
            if ($conflictingPool !== null) {
                $v->errors()->add('cidr', sprintf(
                    "Subnet '%s' overlaps with existing pool '%s' (ID #%d, CIDR %s).",
                    $cidr,
                    $conflictingPool->name,
                    $conflictingPool->id,
                    $conflictingPool->cidr
                ));
            }
        });
    }
}
