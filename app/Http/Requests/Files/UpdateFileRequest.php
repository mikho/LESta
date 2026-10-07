<?php

namespace App\Http\Requests\Files;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateFileRequest extends FormRequest
{
    /**
     * Only a coarse shape/length guard, matching StoreFileRequest's own precedent: real
     * traversal/symlink-escape rejection happens agent-side at apply time.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'path' => ['required', 'string', 'max:1024'],
            'new_path' => ['sometimes', 'string', 'max:1024'],
            'content_base64' => ['sometimes', 'string', 'max:'.StoreFileRequest::maxContentBase64Length()],
        ];
    }

    /**
     * Exactly one of new_path (rename) or content_base64 (overwrite) is expected -- the same
     * guard App\Actions\Files\UpdateFile::handle() enforces again on its own, since this layer's
     * own validation is never trusted as the sole boundary.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasNewPath = $this->filled('new_path');
            $hasContent = $this->has('content_base64');

            if ($hasNewPath === $hasContent) {
                $validator->errors()->add('new_path', 'Exactly one of new_path or content_base64 is required.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return (new StoreFileRequest)->messages();
    }
}
