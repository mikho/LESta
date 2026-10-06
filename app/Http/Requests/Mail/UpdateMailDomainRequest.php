<?php

namespace App\Http\Requests\Mail;

use App\Models\MailDomain;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * No `domain` field: UpdateMailDomain::handle() never accepts one (a mail domain's own domain
 * name is fixed at creation, matching this project's established precedent for provisioned
 * resources whose identity is embedded in real rendered node config).
 */
class UpdateMailDomainRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'antivirus_enabled' => ['nullable', 'boolean'],
            'antispam_enabled' => ['nullable', 'boolean'],
            'dkim_enabled' => ['nullable', 'boolean'],
            'catchall_email' => ['nullable', 'email', 'max:255', $this->catchallIsMailboxOnThisDomain()],
        ];
    }

    /**
     * A catch-all may only point at an existing mailbox on this same domain: delivering to an
     * external address would turn every guessed local part into relayed mail under this server's
     * own reputation.
     */
    private function catchallIsMailboxOnThisDomain(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            /** @var MailDomain $mailDomain */
            $mailDomain = $this->route('mailDomain');

            [$localPart, $domain] = array_pad(explode('@', strtolower((string) $value), 2), 2, '');

            $isMailboxOnThisDomain = $domain === $mailDomain->domain
                && $mailDomain->accounts()->where('local_part', $localPart)->exists();

            if (! $isMailboxOnThisDomain) {
                $fail('The catch-all must be an existing mailbox on this domain.');
            }
        };
    }
}
