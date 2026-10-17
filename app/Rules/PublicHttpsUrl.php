<?php

namespace App\Rules;

use App\Services\Webhooks\WebhookTarget;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An https:// address whose host resolves to public addresses only (see WebhookTarget).
 */
class PublicHttpsUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be text.');

            return;
        }

        $problem = app(WebhookTarget::class)->problem($value);

        if ($problem !== null) {
            $fail($problem);
        }
    }
}
