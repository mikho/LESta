<?php

namespace App\Services\Provisioning;

use App\Contracts\Provisioner;
use App\Models\ProvisioningOperation;

final class DaemonProvisioner implements Provisioner
{
    /**
     * A genuine no-op. By the time DispatchProvisioningOperation calls this, $operation is
     * already Dispatched with its node_id and deadline already set by the job itself; the
     * real work happens later, out of process, when the owning node's agent daemon picks the
     * operation up on its next heartbeat and reports a result back over
     * agent/v1/operation-results. Returning null tells the job there is no terminal result
     * to write yet.
     */
    public function apply(ProvisioningOperation $operation): ?ProvisioningResult
    {
        return null;
    }
}
