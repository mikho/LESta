<?php

namespace App\Actions\Provisioning;

use App\Actions\Nodes\ResolvesNodeServiceRemoval;
use App\Enums\ProvisioningStatus;
use App\Models\NodeCapability;
use App\Models\NodeServiceAction;
use App\Models\ProvisioningOperation;

/**
 * When a node reports a service removed, removes the node's capabilities for that service, so the
 * panel no longer places anything there or expects a heartbeat for it.
 */
class RecordsNodeServiceRemoval
{
    public function handle(ProvisioningOperation $operation): void
    {
        if ($operation->capability !== NodeServiceAction::UNINSTALL_CAPABILITY || $operation->status !== ProvisioningStatus::Applied) {
            return;
        }

        $service = $operation->payload['service'] ?? null;
        $capabilities = is_string($service) ? (ResolvesNodeServiceRemoval::SERVICES[$service] ?? []) : [];

        if ($capabilities === []) {
            return;
        }

        NodeCapability::query()
            ->where('node_id', $operation->node_id)
            ->whereIn('capability', $capabilities)
            ->get()
            ->each(fn (NodeCapability $capability) => $capability->delete());
    }
}
