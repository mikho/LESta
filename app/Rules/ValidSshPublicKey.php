<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validates a real OpenSSH public key in "authorized_keys" line format:
 * "<type> <base64-blob> [comment]". Decodes the base64 blob and checks its
 * own wire-format length-prefixed type string matches the declared type
 * exactly (RFC 4251 section 5's string encoding: a 4-byte big-endian length
 * followed by that many bytes) -- a real structural check, not just "is
 * this base64 and does the first word look right", since either alone would
 * accept a key that decodes to real bytes but whose declared type is a lie.
 * This is defense in depth; step 2 of Web Application Hosting Threat Model
 * and Isolation Design.md is what actually authenticates a real SFTP
 * session against this key, on the node itself, via sshd -- this rule only
 * ever protects what Laravel stores.
 */
class ValidSshPublicKey implements ValidationRule
{
    /**
     * @var list<string>
     */
    private const ALLOWED_TYPES = [
        'ssh-ed25519',
        'ssh-rsa',
        'ecdsa-sha2-nistp256',
        'ecdsa-sha2-nistp384',
        'ecdsa-sha2-nistp521',
    ];

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail('The :attribute must be a valid SSH public key.');

            return;
        }

        // Keys legitimately run to several KB (an RSA-4096 key's base64 blob alone is over
        // 700 characters); 8192 is a generous ceiling against a pathological input, not a real
        // key-size limit.
        if (mb_strlen($value) > 8192) {
            $fail('The :attribute must be a valid SSH public key.');

            return;
        }

        $parts = preg_split('/\s+/', trim($value), 3);

        if ($parts === false || count($parts) < 2) {
            $fail('The :attribute must be a valid SSH public key.');

            return;
        }

        [$type, $base64] = $parts;

        if (! in_array($type, self::ALLOWED_TYPES, true)) {
            $fail('The :attribute must be a supported SSH key type ('.implode(', ', self::ALLOWED_TYPES).').');

            return;
        }

        $blob = base64_decode($base64, true);

        if ($blob === false || mb_strlen($blob, '8bit') < 4) {
            $fail('The :attribute must be a valid SSH public key.');

            return;
        }

        $declaredLength = unpack('N', substr($blob, 0, 4));

        if ($declaredLength === false) {
            $fail('The :attribute must be a valid SSH public key.');

            return;
        }

        $length = $declaredLength[1];
        $embeddedType = substr($blob, 4, $length);

        if ($embeddedType !== $type) {
            $fail('The :attribute must be a valid SSH public key.');
        }
    }
}
