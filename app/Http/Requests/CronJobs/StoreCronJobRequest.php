<?php

namespace App\Http\Requests\CronJobs;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreCronJobRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * Only a coarse shape/length guard: the real cron-field grammar (numeric range checks per
     * field) and the command's own newline/sudo checks are enforced by the Go capability at
     * apply time, mirroring how StoreDnsZoneRequest doesn't re-validate DNS record grammar
     * either.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'minute' => ['sometimes', 'string', 'max:32'],
            'hour' => ['sometimes', 'string', 'max:32'],
            'day_of_month' => ['sometimes', 'string', 'max:32'],
            'month' => ['sometimes', 'string', 'max:32'],
            'day_of_week' => ['sometimes', 'string', 'max:32'],
            'command' => ['required', 'string', 'max:1024'],
        ];
    }
}
