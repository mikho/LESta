<?php

namespace App\Actions\Provisioning;

use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningVerb;
use App\Models\Backup;
use App\Models\ProvisioningOperation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Mirrors RecordsBackupArtifact's role: the one place "an Observe operation against a Backup
 * just completed, and it reported the artifact's own sealed bytes" knowledge lives.
 * backup.encrypted-artifacts.v1's own applyObserve reports {artifact_base64} on a successful
 * result's own ResultEnvelope.Data; this hook decrypts it with the plaintext encryption key
 * Backup::encryption_key already holds (the control plane, never the node, is this key's real
 * home -- see the backups migration's own doc comment) and writes the real plaintext archive to
 * local storage for BackupController::download() to stream once ready. Never throws: a
 * provisioning-completion hook has no HTTP request to surface a validation error to, so a
 * malformed or undecryptable report is a logged warning, leaving the backup's own download
 * fields untouched (the admin can simply prepare again).
 */
class PreparesBackupDownload
{
    /**
     * How long a decrypted, downloadable copy is allowed to sit on local storage before
     * BackupController::download() refuses to serve it and a scheduled prune command deletes it.
     * Deliberately short: unlike the sealed, still-encrypted node-side artifact, this copy is
     * real plaintext config-plane content sitting on the control plane's own disk.
     */
    private const DOWNLOAD_TTL_MINUTES = 30;

    public function handle(ProvisioningOperation $operation): void
    {
        $backup = $operation->provisionable;
        if (! $backup instanceof Backup) {
            return;
        }

        if ($operation->operation !== ProvisioningVerb::Observe) {
            return;
        }

        if (! in_array($operation->status, [ProvisioningStatus::Applied, ProvisioningStatus::AlreadyApplied], true)) {
            return;
        }

        $base64 = $operation->data['artifact_base64'] ?? null;
        if (! is_string($base64) || $base64 === '') {
            return;
        }

        $sealed = base64_decode($base64, true);
        if ($sealed === false) {
            Log::warning('Cannot prepare backup download: artifact_base64 was not valid base64.', ['backup_id' => $backup->id]);

            return;
        }

        $plaintext = $this->decrypt($sealed, $backup->encryption_key);
        if ($plaintext === null) {
            Log::warning('Cannot prepare backup download: decrypting the artifact failed.', ['backup_id' => $backup->id]);

            return;
        }

        $path = 'backup-downloads/'.$backup->uuid.'.tar.gz';
        Storage::disk('local')->put($path, $plaintext);

        $backup->forceFill([
            'download_path' => $path,
            'download_ready_at' => now(),
            'download_expires_at' => now()->addMinutes(self::DOWNLOAD_TTL_MINUTES),
        ])->save();
    }

    /**
     * Reverses agent/internal/capability/backup/crypto.go's own encrypt(): the sealed bytes are
     * nonce(12) || ciphertext || tag(16) concatenated, AES-256-GCM. PHP's openssl_decrypt wants
     * the tag passed separately from the ciphertext, unlike Go's cipher.AEAD, which appends it.
     */
    private function decrypt(string $sealed, string $hexKey): ?string
    {
        if (strlen($sealed) < 12 + 16) {
            return null;
        }

        $key = hex2bin($hexKey);
        if ($key === false || strlen($key) !== 32) {
            return null;
        }

        $nonce = substr($sealed, 0, 12);
        $tag = substr($sealed, -16);
        $ciphertext = substr($sealed, 12, -16);

        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);

        return $plaintext === false ? null : $plaintext;
    }
}
