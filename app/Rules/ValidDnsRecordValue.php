<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The value of an SRV, CAA or TXT record, the three types whose value is more than an address or a
 * host name. Values become zone file text, where a line break or a leading "$" would start a line
 * or directive of its own, so each must match its type's own grammar. The node checks the same
 * (bind9 values.go) and refuses the whole zone otherwise; this gives the customer the reason here.
 */
class ValidDnsRecordValue implements ValidationRule
{
    private const string TARGET = '(?:\.|[A-Za-z0-9_](?:[A-Za-z0-9_-]{0,61}[A-Za-z0-9_])?(?:\.[A-Za-z0-9_](?:[A-Za-z0-9_-]{0,61}[A-Za-z0-9_])?)*\.?)';

    public function __construct(private string $type) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail(__('The value is not valid.'));

            return;
        }

        $valid = match ($this->type) {
            'SRV' => preg_match('/^(\d{1,5}) (\d{1,5}) '.self::TARGET.'$/', $value, $m) === 1
                && (int) $m[1] <= 65535 && (int) $m[2] <= 65535 && mb_strlen($value) <= 270,
            'CAA' => preg_match('/^(\d{1,3}) (issue|issuewild|iodef|issuemail|issuevmc) "([^"\\\\\x00-\x1f\x7f]{0,255})"$/', $value, $m) === 1
                && (int) $m[1] <= 255,
            'TXT' => preg_match('/[\x00-\x1f\x7f]/', $value) !== 1,
            default => true,
        };

        if (! $valid) {
            $fail(match ($this->type) {
                'SRV' => __('An SRV value is "weight port target", for example: 5 5060 sip.example.com.'),
                'CAA' => __('A CAA value is "flags tag \"value\"", for example: 0 issue "letsencrypt.org"'),
                default => __('A TXT value cannot contain line breaks or other control characters.'),
            });
        }
    }
}
