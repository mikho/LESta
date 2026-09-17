<?php

namespace App\Http\Requests\Support;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StartImpersonationRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * A reason is required: App\Actions\Support\StartImpersonation records it on the same
     * impersonation.started AuditEvent that already logs who did this and to whom, so a stated
     * reason is captured at the moment of the real session swap rather than left to be
     * reconstructed later from a support ticket.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
