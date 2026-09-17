<?php

namespace App\Http\Requests\Nodes;

use App\Enums\NodeCapabilityStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateNodeCapabilityStatusRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * Running is deliberately excluded: it can only ever be set by a confirmed agent heartbeat
     * (AgentHeartbeatController::store), never a manual admin change -- see
     * UpdateNodeCapabilityStatus, which rejects it again as the actual, unbypassable boundary.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in([
                NodeCapabilityStatus::NotInstalled->value,
                NodeCapabilityStatus::Stopped->value,
            ])],
        ];
    }
}
