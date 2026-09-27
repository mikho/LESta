<?php

namespace App\Http\Requests\Domains;

use App\Rules\ValidSshPublicKey;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSshPublicKeyRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ssh_public_key' => ['nullable', 'string', new ValidSshPublicKey],
        ];
    }
}
