<?php

namespace App\Actions\Provisioning;

use App\Enums\ProvisioningStatus;
use App\Models\Node;
use App\Models\NodeAgentUpgrade;
use App\Models\ProvisioningOperation;

/**
 * When a node reports an agent upgrade applied that also rotated its upgrade key, records the new
 * key as the one the node trusts. The agent's own version arrives with its next heartbeat.
 */
class RecordsAgentUpgradeResult
{
    public function handle(ProvisioningOperation $operation): void
    {
        if ($operation->capability !== NodeAgentUpgrade::CAPABILITY || $operation->status !== ProvisioningStatus::Applied) {
            return;
        }

        $upgrade = NodeAgentUpgrade::query()->find($operation->provisionable_id);

        if ($upgrade === null || $upgrade->next_key_id === null) {
            return;
        }

        Node::query()->whereKey($upgrade->node_id)->update(['upgrade_key_id' => $upgrade->next_key_id]);
    }
}
