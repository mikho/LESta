<?php

namespace App\Http\Requests\Settings;

use App\Models\Webhook;
use App\Rules\PublicHttpsUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWebhookRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:2048', new PublicHttpsUrl],
            'account' => ['nullable', 'string', Rule::exists('accounts', 'public_id')->where('is_platform', false)],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::in(Webhook::EVENTS)],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
