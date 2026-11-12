<?php

namespace App\Actions\Provisioning;

use App\Enums\ProvisioningStatus;
use App\Models\Node;
use App\Models\NodeServiceAction;
use App\Models\ProvisioningOperation;

/**
 * When the node reports that it opened or closed its ssh port, the panel shows the port as the node
 * left it. A failed attempt changes nothing: the node did not say it had.
 */
class RecordsNodeSshAccess
{
    public function handle(ProvisioningOperation $operation): void
    {
        if ($operation->capability !== NodeServiceAction::SSH_CAPABILITY
            || ! in_array($operation->status, [ProvisioningStatus::Applied, ProvisioningStatus::AlreadyApplied], true)) {
            return;
        }

        $open = $operation->data['open'] ?? null;

        if (! is_bool($open)) {
            return;
        }

        Node::query()->whereKey($operation->node_id)->update(['ssh_open' => $open]);
    }
}
