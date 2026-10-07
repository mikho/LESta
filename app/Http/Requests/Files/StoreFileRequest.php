<?php

namespace App\Http\Requests\Files;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreFileRequest extends FormRequest
{
    /**
     * The largest file the browser file manager accepts. It travels base64-encoded and encrypted in
     * a provisioning payload, so this is also bounded by that column (see the migration that
     * widened it) and by PHP's post_max_size and the web server's request body limit.
     */
    public const int MAX_FILE_BYTES = 4 * 1024 * 1024;

    /**
     * The base64 text length of a file of MAX_FILE_BYTES.
     */
    public static function maxContentBase64Length(): int
    {
        return intdiv(self::MAX_FILE_BYTES + 2, 3) * 4;
    }

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
            'content_base64' => ['sometimes', 'string', 'max:'.self::maxContentBase64Length()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'content_base64.max' => 'This file is larger than '.intdiv(self::MAX_FILE_BYTES, 1024 * 1024).' MB, the most the file manager can upload.',
        ];
    }
}
