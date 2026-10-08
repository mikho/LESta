<?php

namespace App\Http\Requests\Mail;

use App\Models\MailDomain;
use App\Rules\ValidMailboxLocalPart;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMailAccountRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var MailDomain $mailDomain */
        $mailDomain = $this->route('mailDomain');

        return [
            'local_part' => [
                'required', 'string', new ValidMailboxLocalPart,
                Rule::unique('mail_accounts', 'local_part')->where(fn ($query) => $query->where('mail_domain_id', $mailDomain->id)),
                // A mailing list answers to <name>@ and <name>-owner@; a mailbox cannot take either.
                function (string $attribute, mixed $value, \Closure $fail) use ($mailDomain): void {
                    $name = mb_strtolower((string) $value);

                    $taken = $mailDomain->mailingLists()->get(['local_part'])->contains(
                        fn ($list): bool => $name === $list->local_part || $name === $list->local_part.'-owner',
                    );

                    if ($taken) {
                        $fail(__('That name is used by a mailing list.'));
                    }
                },
            ],
            'quota_mb' => ['nullable', 'integer', 'min:1'],
            'forward_to' => ['nullable', 'email', 'max:255'],
            'forward_only' => ['nullable', 'boolean'],
            'autoreply_enabled' => ['nullable', 'boolean'],
            'autoreply_message' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
