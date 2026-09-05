<?php

namespace App\Http\Requests\Dns;

use App\Enums\DnsRecordType;
use App\Rules\ValidDnsRecordName;
use App\Rules\ValidDnsTargetHostname;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Two deliberate scope boundaries, not oversights:
 *
 * 1. No name+type uniqueness rule. Multiple A/NS/MX records sharing a name is valid, desired DNS
 *    (round-robin, multiple mail exchangers), the database's uniqueness constraint is on
 *    (zone, name, type, value), not (zone, name, type).
 * 2. No structured parsing of SRV's "weight port target" or CAA's "flags tag value" sub-fields.
 *    `value` stays one opaque string end-to-end, matching Phase 5's own already-made decision.
 *    Full type-specific wire-format validation is out of scope for this phase.
 */
class StoreDnsRecordRequest extends FormRequest
{
    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $type = (string) $this->input('type');

        $this->merge([
            'priority' => in_array($type, ['MX', 'SRV'], true) ? $this->input('priority') : null,
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $type = (string) $this->input('type');

        return [
            'name' => ['required', 'string', 'max:191', new ValidDnsRecordName],
            'type' => ['required', 'string', Rule::enum(DnsRecordType::class)],
            'priority' => ['nullable', 'integer', 'min:0', 'max:65535', Rule::requiredIf(in_array($type, ['MX', 'SRV'], true))],
            'value' => match ($type) {
                'A' => ['required', 'string', 'ip', 'ipv4'],
                'AAAA' => ['required', 'string', 'ip', 'ipv6'],
                'CNAME', 'NS', 'PTR', 'MX' => ['required', 'string', 'max:255', new ValidDnsTargetHostname],
                'TXT', 'SRV', 'CAA' => ['required', 'string', 'max:2048'],
                default => ['required', 'string', 'max:255'],
            },
        ];
    }
}
