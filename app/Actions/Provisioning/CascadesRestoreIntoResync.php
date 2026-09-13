<?php

namespace App\Actions\Provisioning;

use App\Actions\Nodes\ResyncNode;
use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningVerb;
use App\Models\Backup;
use App\Models\ProvisioningOperation;

/**
 * Once a Restore operation against a Backup completes, brings the rest of its owning node back in
 * sync with current desired state (see App\Actions\Nodes\ResyncNode's own doc comment for why
 * that half of disaster recovery is a completely different, much cheaper mechanism than replaying
 * an old archive). Runs regardless of the restore's own outcome for anything BUT a genuine
 * success: a rejected or failed restore has put nothing back, so there is nothing new to resync
 * against, and resyncing anyway would just be a same-state no-op at best.
 */
class CascadesRestoreIntoResync
{
    public function __construct(private ResyncNode $resyncNode) {}

    public function handle(ProvisioningOperation $operation): void
    {
        $backup = $operation->provisionable;
        if (! $backup instanceof Backup) {
            return;
        }

        if ($operation->operation !== ProvisioningVerb::Restore) {
            return;
        }

        if (! in_array($operation->status, [ProvisioningStatus::Applied, ProvisioningStatus::AlreadyApplied], true)) {
            return;
        }

        $this->resyncNode->handleSystemInitiated($backup->node);
    }
}
