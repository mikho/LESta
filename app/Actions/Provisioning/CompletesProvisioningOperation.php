<?php

namespace App\Actions\Provisioning;

use App\Models\ProvisioningOperation;
use App\Services\Provisioning\ProvisioningResult;

/**
 * Writes a provisioner's terminal ProvisioningResult onto its ProvisioningOperation row and
 * fires the hook points that depend on it. Shared by DispatchProvisioningOperation's own
 * synchronous provisioners (fake) and AgentOperationResultController's asynchronous
 * daemon-reported path, so both routes to a terminal result complete a row identically.
 */
class CompletesProvisioningOperation
{
    public function __construct(
        private TriggersAcmeCertificateIssuance $triggersAcmeCertificateIssuance,
        private RecordsBackupArtifact $recordsBackupArtifact,
        private RecordsUsageSnapshot $recordsUsageSnapshot,
    ) {}

    public function handle(ProvisioningOperation $operation, ProvisioningResult $result): void
    {
        $operation->forceFill([
            'status' => $result->status,
            'observed_state_version' => $result->observedStateVersion,
            'observed_state_digest' => $result->observedStateDigest,
            'generation_id' => $result->generationId,
            'errors' => $result->errors,
            'completed_at' => $result->completedAt,
            'data' => $result->data,
        ])->save();

        $this->triggersAcmeCertificateIssuance->handle($operation);
        $this->recordsBackupArtifact->handle($operation);
        $this->recordsUsageSnapshot->handle($operation);
    }
}
