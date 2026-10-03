<?php

namespace App\Http\Requests\Files;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreFileRequest extends FormRequest
{
    /**
     * Only a coarse shape/length guard, matching StoreCronJobRequest's own precedent: real
     * traversal/symlink-escape rejection happens agent-side at apply time
     * (agent/internal/capability/files's own ParsePayload and resolveSafePath), never trusted to
     * this layer alone.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'path' => ['sometimes', 'string', 'max:1024'],
            'is_directory' => ['sometimes', 'boolean'],
            'content_base64' => ['sometimes', 'string'],
        ];
    }
}
