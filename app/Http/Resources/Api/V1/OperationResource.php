<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\ProvisioningStatus;
use App\Models\AuditEvent;
use App\Models\ProvisioningOperation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * One change made through the API, as the nodes apply it: the audit event that recorded the
 * change and every provisioning operation sharing its correlation id.
 *
 * @mixin AuditEvent
 */
class OperationResource extends ApiResource
{
    /**
     * @param  Collection<int, ProvisioningOperation>  $operations
     */
    public function __construct(AuditEvent $event, private readonly Collection $operations)
    {
        parent::__construct($event);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->correlation_id,
            'action' => $this->action,
            'status' => self::aggregateStatus($this->operations),
            'steps' => $this->operations->map(fn (ProvisioningOperation $operation): array => [
                'capability' => $operation->capability,
                'verb' => $operation->operation->value,
                'status' => $operation->status->value,
                'errors' => collect($operation->errors ?? [])->map(fn (array $error): array => ['code' => $error['code'], 'message' => $error['message']])->all(),
                'completed_at' => $this->timestamp($operation->completed_at),
            ])->all(),
            'created_at' => $this->timestamp($this->created_at),
        ];
    }

    /**
     * failed when any step failed or was refused, pending while any step is still on its way,
     * applied once every step is done (also when the change needed no node at all).
     *
     * @param  Collection<int, ProvisioningOperation>  $operations
     * @return 'pending'|'applied'|'failed'
     */
    public static function aggregateStatus(Collection $operations): string
    {
        $statuses = $operations->pluck('status');

        return match (true) {
            $statuses->contains(fn (ProvisioningStatus $status): bool => in_array($status, [ProvisioningStatus::Failed, ProvisioningStatus::Rejected, ProvisioningStatus::Degraded], true)) => 'failed',
            $statuses->contains(fn (ProvisioningStatus $status): bool => in_array($status, [ProvisioningStatus::Pending, ProvisioningStatus::Dispatched], true)) => 'pending',
            default => 'applied',
        };
    }
}
