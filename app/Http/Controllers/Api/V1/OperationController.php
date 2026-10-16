<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\OperationResource;
use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\ProvisioningOperation;
use Illuminate\Http\Request;

class OperationController extends ApiController
{
    /**
     * A change this token made, and how far the nodes are with it. Another token's changes, and
     * changes made in the panel, are not found here.
     */
    public function show(Request $request, string $operation): OperationResource
    {
        /** @var ApiToken $token */
        $token = $request->attributes->get('api_token');

        $event = AuditEvent::query()
            ->where('correlation_id', $operation)
            ->where('api_token_id', $token->id)
            ->where('actor_type', $request->user()->getMorphClass())
            ->where('actor_id', $request->user()->getKey())
            ->firstOrFail();

        return new OperationResource($event, ProvisioningOperation::query()->where('correlation_id', $operation)->orderBy('id')->get());
    }
}
