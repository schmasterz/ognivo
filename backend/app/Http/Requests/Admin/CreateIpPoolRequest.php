<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\DTO\Network\CreateIpPoolDTO;
use App\Services\IpManagementService;
use App\Services\Network\IpPoolConfigValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Form Request validating IP subnet pool creation payload.
 */
final class CreateIpPoolRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'host_id' => ['required', 'integer', 'exists:hosts,id'],
            'cidr' => ['required_without:subnet', 'nullable', 'string', 'max:64'],
            'subnet' => ['required_without:cidr', 'nullable', 'string', 'max:64'],
            'first_usable' => ['nullable', 'ip'],
            'last_usable' => ['nullable', 'ip'],
            'parent_interface' => ['required_unless:network_mode,manual', 'nullable', 'string', 'max:32'],
            'network_mode' => ['nullable', 'string', Rule::in(['routed', 'bridged', 'macvlan', 'manual'])],
            'gateway' => ['nullable', 'ip'],
            'dns_servers' => ['nullable', 'array'],
            'dns_servers.*' => ['ip'],
            'excluded_ips' => ['nullable', 'array'],
            'excluded_ips.*' => ['ip'],
            'cooldown_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'ip_version' => ['nullable', 'integer'],
            'is_public' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:255'],
            'auto_generate_ips' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($this->has('ip_version')) {
                $ver = (int) $this->input('ip_version');
                if ($ver !== 4) {
                    $v->errors()->add('ip_version', __('messages.ip.ipv6_not_supported_v1'));

                    return;
                }
            }

            $cidr = (string) ($this->input('cidr') ?: $this->input('subnet'));
            if ($cidr === '') {
                return;
            }

            $rawMode = $this->input('network_mode');
            $networkMode = is_string($rawMode) && $rawMode !== '' ? $rawMode : 'routed';
            if (! in_array($networkMode, ['routed', 'bridged', 'macvlan', 'manual'], true)) {
                return;
            }

            $rawGateway = $this->input('gateway');
            $gateway = is_string($rawGateway) && trim($rawGateway) !== '' ? $rawGateway : null;
            $excludedIps = is_array($this->input('excluded_ips')) ? $this->input('excluded_ips') : [];
            $rawFirst = $this->input('first_usable');
            $firstUsable = is_string($rawFirst) && trim($rawFirst) !== '' ? $rawFirst : null;
            $rawLast = $this->input('last_usable');
            $lastUsable = is_string($rawLast) && trim($rawLast) !== '' ? $rawLast : null;

            $errors = IpPoolConfigValidator::validate($networkMode, $cidr, $gateway, $excludedIps, $firstUsable, $lastUsable);
            foreach ($errors as $field => $messages) {
                foreach ($messages as $msg) {
                    $v->errors()->add($field, $msg);
                }
            }

            // Overlap detection
            $hostId = (int) $this->input('host_id');
            $isPublic = $this->has('is_public') ? (bool) $this->input('is_public') : true;

            $conflictingPool = app(IpManagementService::class)->checkPoolOverlap($cidr, $hostId, $isPublic);
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

    public function toDTO(): CreateIpPoolDTO
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        if (empty($validated['cidr']) && ! empty($validated['subnet'])) {
            $validated['cidr'] = $validated['subnet'];
        }

        return CreateIpPoolDTO::fromArray($validated);
    }
}
