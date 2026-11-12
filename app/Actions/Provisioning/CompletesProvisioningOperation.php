<?php

namespace App\Actions\Provisioning;

use App\Actions\Webhooks\QueuesFinishedWebhookEvents;
use App\Models\ProvisioningOperation;
use App\Services\Provisioning\ProvisioningResult;
use Throwable;

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
        private PublishesDkimDnsRecord $publishesDkimDnsRecord,
        private PreparesBackupDownload $preparesBackupDownload,
        private CascadesRestoreIntoResync $cascadesRestoreIntoResync,
        private RecordsAccountBackupResult $recordsAccountBackupResult,
        private RecordsNodeServiceState $recordsNodeServiceState,
        private RecordsNodeSshAccess $recordsNodeSshAccess,
        private RecordsNodeServiceRemoval $recordsNodeServiceRemoval,
        private RecordsAgentUpgradeResult $recordsAgentUpgradeResult,
    ) {}

    /**
     * Completes the operation and runs the hooks, once: the row is claimed with a compare-and-set on
     * the status it was read with, so a result reported twice (a daemon resending a batch, two
     * requests racing) completes it only the first time. Each hook runs on its own, so one that
     * throws is reported and the others still run.
     *
     * @return bool whether this call completed the operation (false when another already had)
     */
    public function handle(ProvisioningOperation $operation, ProvisioningResult $result): bool
    {
        $readStatus = $operation->getOriginal('status');

        $operation->forceFill([
            'status' => $result->status,
            'observed_state_version' => $result->observedStateVersion,
            'observed_state_digest' => $result->observedStateDigest,
            'generation_id' => $result->generationId,
            'errors' => $result->errors,
            'completed_at' => $result->completedAt,
            'data' => $result->data,
        ]);

        $claimed = ProvisioningOperation::query()
            ->whereKey($operation->getKey())
            ->where('status', $readStatus)
            ->update($operation->getDirty());

        if ($claimed === 0) {
            $operation->refresh();

            return false;
        }

        $operation->syncOriginal();

        $hooks = [
            $this->triggersAcmeCertificateIssuance,
            $this->recordsBackupArtifact,
            $this->recordsUsageSnapshot,
            $this->publishesDkimDnsRecord,
            $this->preparesBackupDownload,
            $this->cascadesRestoreIntoResync,
            $this->recordsAccountBackupResult,
            $this->recordsNodeServiceState,
            $this->recordsNodeSshAccess,
            $this->recordsNodeServiceRemoval,
            $this->recordsAgentUpgradeResult,
            // Last, so the events see every record above as already updated.
            app(QueuesFinishedWebhookEvents::class),
        ];

        foreach ($hooks as $hook) {
            try {
                $hook->handle($operation);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return true;
    }
}
