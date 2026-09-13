<?php

namespace App\Actions\Provisioning;

use App\Actions\Backups\DeleteBackup;
use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningVerb;
use App\Models\Backup;
use App\Models\ProvisioningOperation;

/**
 * Mirrors TriggersAcmeCertificateIssuance's role: the one place "a backup operation just
 * completed" knowledge lives on the completion side. A Create's ResultEnvelope.Data carries the
 * artifact's derived, non-secret metadata (see docs/protocol/result-envelope.schema.json's own
 * "data" property) since nothing about a finished backup artifact -- its size, checksum, on-disk
 * path, which capabilities it actually captured -- is knowable ahead of the daemon doing the
 * real tar/encrypt/write work asynchronously. A Delete or a Failed result carries no artifact
 * metadata to record; those are handled by DeleteBackup's own row removal and this hook's own
 * error_message/status/completed_at bookkeeping respectively.
 */
class RecordsBackupArtifact
{
    /**
     * How many completed backups are retained per node; the oldest beyond this count is deleted
     * (row and, via its own dispatched Delete operation, on-disk artifact) whenever a new one
     * completes successfully. Matches agent/internal/generation.Store's own DefaultKeep of 5: no
     * ADR numbers a specific bound, so this reuses the one existing precedent in this codebase
     * rather than inventing a second, different default.
     */
    private const KEEP_PER_NODE = 5;

    public function __construct(private DeleteBackup $deleteBackup) {}

    public function handle(ProvisioningOperation $operation): void
    {
        $backup = $operation->provisionable;
        if (! $backup instanceof Backup) {
            return;
        }

        if ($operation->operation !== ProvisioningVerb::Create) {
            return;
        }

        if (in_array($operation->status, [ProvisioningStatus::Applied, ProvisioningStatus::AlreadyApplied], true)) {
            $data = $operation->data ?? [];

            $backup->forceFill([
                'status' => $operation->status,
                'included_capabilities' => $data['included_capabilities'] ?? null,
                'size_bytes' => $data['size_bytes'] ?? null,
                'checksum' => $data['checksum'] ?? null,
                'artifact_path' => $data['artifact_path'] ?? null,
                'completed_at' => $operation->completed_at,
            ])->save();

            $this->pruneOldest($backup);

            return;
        }

        if ($operation->status === ProvisioningStatus::Failed) {
            $backup->forceFill([
                'status' => ProvisioningStatus::Failed,
                'error_message' => $operation->errors[0]['message'] ?? 'Backup creation failed.',
                'completed_at' => $operation->completed_at,
            ])->save();
        }
    }

    private function pruneOldest(Backup $backup): void
    {
        $excess = Backup::query()
            ->where('node_id', $backup->node_id)
            ->whereIn('status', [ProvisioningStatus::Applied, ProvisioningStatus::AlreadyApplied])
            ->orderByDesc('completed_at')
            ->skip(self::KEEP_PER_NODE)
            ->take(PHP_INT_MAX)
            ->get();

        foreach ($excess as $stale) {
            $this->deleteBackup->handleSystemInitiated($stale);
        }
    }
}
