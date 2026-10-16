<?php

namespace App\Actions\Provisioning;

use App\Models\Node;
use App\Models\NodeServiceAction;
use App\Models\ProvisioningOperation;

/**
 * When a service action finishes, writes the state the node reported for the unit into the node's
 * service list straight away, so the panel does not wait for the next heartbeat to show it.
 */
class RecordsNodeServiceState
{
    public function handle(ProvisioningOperation $operation): void
    {
        if ($operation->capability !== NodeServiceAction::CAPABILITY) {
            return;
        }

        $unit = $operation->data['unit'] ?? null;
        $state = $operation->data['state'] ?? null;
        $node = Node::query()->find($operation->node_id);

        if (! is_string($unit) || ! is_string($state) || $node === null || $state === 'unknown') {
            return;
        }

        $services = collect($node->service_states ?? [])
            ->map(fn (array $service): array => $service['name'] === $unit ? ['name' => $unit, 'state' => $state] : $service)
            ->values()
            ->all();

        $node->forceFill(['service_states' => $services])->save();
    }
}
