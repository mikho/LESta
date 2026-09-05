<?php

namespace App\Http\Requests\Dns;

use App\Enums\DnsRecordType;
use App\Models\DnsRecord;
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
 *
 * This request mirrors the Action's actual partial-update contract (`UpdateDnsRecord::handle()`
 * falls back to the existing record's fields for anything not present in the submitted payload),
 * so `type` defaults to the existing record's current type when not present in the payload. This
 * phase's own UI always resends the full field set from the edit dialog, but these rules should
 * still correctly reflect what the Action actually supports.
 */
class UpdateDnsRecordRequest extends FormRequest
{
    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $type = $this->resolveType();

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
        $type = $this->resolveType();

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

    /**
     * Resolve the effective record type: the submitted value, or the existing record's current
     * type when the payload omits it (the Action's own partial-update contract).
     */
    private function resolveType(): string
    {
        /** @var DnsRecord|null $dnsRecord */
        $dnsRecord = $this->route('dnsRecord');

        return (string) $this->input('type', $dnsRecord?->type->value);
    }
}
