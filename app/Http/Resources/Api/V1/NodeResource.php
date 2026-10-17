<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Node;
use App\Models\NodeCapability;
use Illuminate\Http\Request;

/**
 * A node as the admin dashboard and node page show it: reachability, its services and its
 * capabilities. Never its credential or enrollment token.
 *
 * @mixin Node
 */
class NodeResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $reachable = $this->isAgentReachable();

        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'hostname' => $this->hostname,
            'mail_hostname' => $this->mail_hostname,
            'enrollment_status' => $this->enrollment_status->value,
            'agent_version' => $this->agent_version,
            'agent_reachable' => $reachable,
            'last_seen_at' => $this->timestamp($this->last_seen_at),
            'ping' => ['ok' => $this->ping_ok, 'ms' => $this->ping_ms, 'at' => $this->timestamp($this->pinged_at)],
            'services' => $this->service_states ?? [],
            'capabilities' => $this->whenLoaded('capabilities', fn (): array => $this->capabilities->map(fn (NodeCapability $capability): array => [
                'capability' => $capability->capability,
                'status' => match (true) {
                    $capability->isSuspended() => 'suspended',
                    ! $reachable => 'unknown',
                    default => $capability->status->value,
                },
                'last_seen_at' => $this->timestamp($capability->last_seen_at),
            ])->all()),
            'backups_scheduled' => $this->backups_scheduled,
            ...$this->suspension($this->suspended_at, $this->suspension_source),
            'created_at' => $this->timestamp($this->created_at),
        ];
    }
}
