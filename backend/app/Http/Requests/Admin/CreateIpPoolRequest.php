<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\DTO\Network\CreateIpPoolDTO;
use App\Services\IpManagementService;
use App\Services\Network\CidrHelper;
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
            'parent_interface' => ['nullable', 'string', 'max:32'],
            'network_mode' => ['nullable', 'string', Rule::in(['routed', 'manual'])],
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

            $firstUsable = $this->input('first_usable');
            if ($firstUsable && is_string($firstUsable)) {
                $firstLong = CidrHelper::ipToUnsigned($firstUsable);
                if ($firstLong === null || $minAllowed === null || $maxAllowed === null || $firstLong < $minAllowed || $firstLong > $maxAllowed) {
                    $v->errors()->add('first_usable', 'First usable IP is outside the CIDR subnet.');
                }
            }

            $lastUsable = $this->input('last_usable');
            if ($lastUsable && is_string($lastUsable)) {
                $lastLong = CidrHelper::ipToUnsigned($lastUsable);
                if ($lastLong === null || $minAllowed === null || $maxAllowed === null || $lastLong < $minAllowed || $lastLong > $maxAllowed) {
                    $v->errors()->add('last_usable', 'Last usable IP is outside the CIDR subnet.');
                }
            }

            if ($firstUsable && $lastUsable && is_string($firstUsable) && is_string($lastUsable)) {
                $firstLong = CidrHelper::ipToUnsigned($firstUsable);
                $lastLong = CidrHelper::ipToUnsigned($lastUsable);
                if ($firstLong !== null && $lastLong !== null && $firstLong > $lastLong) {
                    $v->errors()->add('first_usable', 'First usable IP cannot be greater than last usable IP.');
                }
            }

            $gateway = $this->input('gateway');
            if ($prefix <= 30 && (empty($gateway) || ! is_string($gateway))) {
                $v->errors()->add('gateway', 'Gateway is required for subnets /16 to /30.');
            }

            if ($gateway && is_string($gateway)) {
                $gwLong = CidrHelper::ipToUnsigned($gateway);
                if ($gwLong === null || $gwLong < $network || $gwLong > $broadcast) {
                    $v->errors()->add('gateway', 'Gateway IP is outside the CIDR subnet.');
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
