<?php

namespace App\Http\Requests\Agent;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreCronExecutionsRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'executions' => ['required', 'array', 'max:500'],
            'executions.*.resource_id' => ['required', 'uuid'],
            'executions.*.started_at' => ['required', 'date'],
            'executions.*.finished_at' => ['required', 'date'],
            'executions.*.exit_code' => ['required', 'integer'],
            'executions.*.output' => ['required', 'string', 'max:70000'],
        ];
    }
}
