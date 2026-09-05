<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class ValidDnsRecordName implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail('The :attribute must be a valid DNS record name.');

            return;
        }

        // 191, not 255: matches the dns_records.name column's own bound (see the
        // create_dns_records_table migration's comment for the InnoDB key-length math).
        if (mb_strlen($value) > 191) {
            $fail('The :attribute must be a valid DNS record name.');

            return;
        }

        if (preg_match('/^(?:@|\*|[a-zA-Z0-9_](?:[a-zA-Z0-9_-]{0,61}[a-zA-Z0-9_])?(?:\.[a-zA-Z0-9_](?:[a-zA-Z0-9_-]{0,61}[a-zA-Z0-9_])?)*)$/', $value) !== 1) {
            $fail('The :attribute must be a valid DNS record name.');
        }
    }
}
