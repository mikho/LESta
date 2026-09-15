<?php

namespace App\Http\Requests\Mail;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * No `local_part` field: UpdateMailAccount::handle() never accepts one -- mirrors
 * UpdateMailDomainRequest's own "not accepted by the action" precedent, and matches
 * TenantDatabase's own established rule that identity fields are fixed at creation.
 */
class UpdateMailAccountRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'quota_mb' => ['nullable', 'integer', 'min:1'],
            'forward_to' => ['nullable', 'email', 'max:255'],
            'forward_only' => ['nullable', 'boolean'],
            'autoreply_enabled' => ['nullable', 'boolean'],
            'autoreply_message' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
