<?php

namespace App\Http\Requests\Memberships;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMembershipRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * name is always required, even when email turns out to belong to an existing user --
     * App\Actions\Memberships\InviteMember ignores it in that case, mirroring
     * StoreAccountRequest's own owner_name/owner_email precedent exactly.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in(['owner', 'member'])],
        ];
    }
}
