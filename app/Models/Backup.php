<?php

namespace App\Models;

use App\Concerns\HasUuid;
use App\Contracts\ProviderAdminManaged;
use App\Enums\ProvisioningStatus;
use Database\Factories\BackupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;

/**
 * A real, encrypted, whole-node snapshot artifact, provider-admin-only (never tenant-facing):
 * see the Backups design decision (vault) for why. Node-scoped rather than Account-scoped, since
 * a real backup today captures everything Config's backs_up-declared capabilities have on the
 * node, commingling every account hosted there -- no capability yet tags its own on-disk state by
 * account. Create-only, per the legacy system's own precedent ("no suspend/unsuspend concept for
 * backups"): a Backup is either created (dispatched, eventually applied or failed) or deleted,
 * never updated, suspended, or unsuspended.
 *
 * @property int $id
 * @property string $uuid
 * @property int $node_id
 * @property string|null $label
 * @property string $encryption_key
 * @property ProvisioningStatus $status
 * @property array<int, string>|null $included_capabilities
 * @property int|null $size_bytes
 * @property string|null $checksum
 * @property string|null $artifact_path
 * @property string|null $download_path
 * @property Carbon|null $download_ready_at
 * @property Carbon|null $download_expires_at
 * @property string|null $error_message
 * @property int $desired_state_version
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['node_id', 'label', 'encryption_key', 'desired_state_version'])]
class Backup extends Model implements ProviderAdminManaged
{
    /** @use HasFactory<BackupFactory> */
    use HasFactory, HasUuid;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'encryption_key' => 'encrypted',
            'status' => ProvisioningStatus::class,
            'included_capabilities' => 'array',
            'completed_at' => 'datetime',
            'download_ready_at' => 'datetime',
            'download_expires_at' => 'datetime',
        ];
    }

    /**
     * Route model binding resolves by uuid, not the internal auto-increment id.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /**
     * The single most recent provisioning operation for this backup. `MorphMany::latestOfMany()`
     * does not exist in this Laravel version (only `HasOne`/`MorphOne`/`HasOneThrough` support
     * the "of many" relation subquery); `morphOne()->latestOfMany()` is the idiomatic equivalent.
     *
     * @return MorphOne<ProvisioningOperation, $this>
     */
    public function latestProvisioningOperation(): MorphOne
    {
        return $this->morphOne(ProvisioningOperation::class, 'provisionable')->latestOfMany();
    }

    /**
     * Shape the desired-state payload sent to a provisioner. $plaintextEncryptionKey is
     * explicit, never implicit, mirroring TenantDatabase's own password invariant exactly: this
     * method never decrypts $this->encryption_key itself, so a call site can only ever include
     * the key by deliberately passing the plaintext it just generated in the very same request.
     * CreateBackup always passes it (a fresh key just generated for the new row); DeleteBackup
     * and PrepareBackupDownload never do, since removing an artifact or reading its sealed bytes
     * back both need only its path, not the key that encrypted it -- PrepareBackupDownload's own
     * Observe dispatch reuses this exact same no-key branch as Delete.
     *
     * @return array{label: string|null, encryption_key: string}|array{artifact_path: string|null}
     */
    public function toProvisioningPayload(?string $plaintextEncryptionKey = null): array
    {
        if ($plaintextEncryptionKey !== null) {
            return [
                'label' => $this->label,
                'encryption_key' => $plaintextEncryptionKey,
            ];
        }

        return [
            'artifact_path' => $this->artifact_path,
        ];
    }
}
