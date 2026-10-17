<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\OperationResource;
use App\Models\AuditEvent;
use App\Models\ProvisioningOperation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

abstract class ApiController extends Controller
{
    /**
     * The page size a list asked for with ?limit=, 50 by default and never more than 100.
     */
    protected function limit(Request $request): int
    {
        return max(1, min($request->integer('limit', 50), 100));
    }

    /**
     * The answer to a write: 202 with the resource (null once deleted) and the operation that
     * carries the change to the nodes, whose address is in Location. When the change needs no node
     * (an account's name) the answer is final: 200, or 201 for something new, with operation null;
     * likewise 200 when the call changed nothing (suspending what is already suspended). $once is
     * added to the resource for this answer only: a password that is shown once.
     *
     * @param  array<string, mixed>  $once
     */
    protected function accepted(Request $request, Model $subject, ?JsonResource $resource, array $once = [], bool $created = false): JsonResponse
    {
        // From the application's request, where AuditEvent notes them: a form request carries a
        // copy of the attributes taken before the Action ran.
        /** @var list<AuditEvent> $events */
        $events = request()->attributes->get('api_audit_events', []);

        $event = collect($events)->last(fn (AuditEvent $event): bool => $event->auditable_type === $subject->getMorphClass() && (string) $event->auditable_id === (string) $subject->getKey());

        $data = $resource === null ? null : [...$resource->resolve($request), ...$once];

        $operations = $event === null ? new Collection : ProvisioningOperation::query()->where('correlation_id', $event->correlation_id)->get();

        if ($event === null || $operations->isEmpty()) {
            return response()->json(['data' => $data, 'operation' => null], $event !== null && $created ? 201 : 200);
        }

        $url = route('api.v1.operations.show', $event->correlation_id);

        return response()->json([
            'data' => $data,
            'operation' => ['id' => $event->correlation_id, 'status' => OperationResource::aggregateStatus($operations), 'url' => $url],
        ], 202, ['Location' => $url]);
    }
}
