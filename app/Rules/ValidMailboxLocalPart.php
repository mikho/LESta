<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class ValidMailboxLocalPart implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail('The :attribute must be a valid mailbox name.');

            return;
        }

        // 64, not 255: RFC 5321's own practical local-part bound. No DNS-style @/* wildcard
        // semantics here -- a mailbox local-part is never a pattern, just a name.
        if (mb_strlen($value) > 64) {
            $fail('The :attribute must be a valid mailbox name.');

            return;
        }

        if (preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9._+-]{0,62}[a-zA-Z0-9])?$/', $value) !== 1) {
            $fail('The :attribute must be a valid mailbox name.');
        }
    }
}
