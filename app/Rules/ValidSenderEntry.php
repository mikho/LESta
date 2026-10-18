<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * One entry of a mailbox's spam allow or block list: a lower-case address or a domain. The same
 * shapes the node accepts (mail payload.go's memberEmailPattern and domainPattern), since the
 * entries are written into the mailbox's Sieve script.
 */
class ValidSenderEntry implements ValidationRule
{
    private const string DOMAIN = '[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $valid = is_string($value) && (
            preg_match('/^[a-z0-9][a-z0-9._%+-]{0,63}@'.self::DOMAIN.'$/', $value) === 1
            || (mb_strlen($value) <= 253 && preg_match('/^'.self::DOMAIN.'$/', $value) === 1)
        );

        if (! $valid) {
            $fail(__('":value" is not an address (name@example.com) or a domain (example.com).', ['value' => is_string($value) ? $value : '']));
        }
    }
}
