<?php

namespace App\Models;

use App\Concerns\HasUuid;
use App\Enums\ProvisioningStatus;
use Database\Factories\AccountBackupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;

/**
 * A self-service snapshot of one account's data on one node: its site folders, its databases and
 * its mailboxes, as a sealed archive on that node's disk. Distinct from the provider-admin-only,
 * whole-node Backup, which commingles every account. The control plane keeps only the metadata
 * and the per-backup encryption key (encrypted at rest); the archive never leaves the node.
 *
 * Restoring takes a safety snapshot first (kind "before_restore"): the restore is dispatched when
 * that snapshot completes (restore_after_id points at the backup to restore).
 *
 * @property int $id
 * @property string $uuid
 * @property int $account_id
 * @property int $node_id
 * @property string|null $label
 * @property string $kind manual, scheduled, before_restore or imported
 * @property string $encryption_key
 * @property list<string> $requested_parts
 * @property ProvisioningStatus $status
 * @property list<string>|null $parts
 * @property int|null $size_bytes
 * @property string|null $checksum
 * @property string|null $artifact_path
 * @property array<string, mixed>|null $report
 * @property string|null $error_message
 * @property int|null $restore_after_id
 * @property list<string>|null $restore_parts
 * @property string|null $last_restore_status running, applied or failed
 * @property Carbon|null $last_restore_at
 * @property array<string, mixed>|null $last_restore_report
 * @property string|null $last_restore_error
 * @property string|null $remote_status copying, copied or failed
 * @property string|null $remote_key
 * @property string|null $remote_error
 * @property Carbon|null $remote_at
 * @property int $desired_state_version
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['account_id', 'node_id', 'label', 'kind', 'encryption_key', 'requested_parts', 'restore_after_id', 'restore_parts', 'desired_state_version'])]
#[Hidden(['encryption_key'])]
class AccountBackup extends Model
{
    /** @use HasFactory<AccountBackupFactory> */
    use HasFactory, HasUuid;

    /** How many backups of each kind are kept per account and node; the oldest beyond this are deleted. */
    public const int KEEP = 5;

    public const array KEEP_BY_KIND = ['manual' => 5, 'scheduled' => 7, 'before_restore' => 3, 'imported' => 3];

    public const array PARTS = ['files', 'databases', 'mail'];

    protected function casts(): array
    {
        return [
            'encryption_key' => 'encrypted',
            'status' => ProvisioningStatus::class,
            'requested_parts' => 'array',
            'parts' => 'array',
            'report' => 'array',
            'restore_parts' => 'array',
            'last_restore_report' => 'array',
            'last_restore_at' => 'datetime',
            'completed_at' => 'datetime',
            'remote_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /**
     * @return MorphOne<ProvisioningOperation, $this>
     */
    public function latestProvisioningOperation(): MorphOne
    {
        return $this->morphOne(ProvisioningOperation::class, 'provisionable')->latestOfMany();
    }

    /**
     * @return HasMany<AccountBackupDownload, $this>
     */
    public function downloads(): HasMany
    {
        return $this->hasMany(AccountBackupDownload::class);
    }

    /**
     * @return HasMany<AccountBackupImport, $this>
     */
    public function imports(): HasMany
    {
        return $this->hasMany(AccountBackupImport::class);
    }

    public function isComplete(): bool
    {
        return in_array($this->status, [ProvisioningStatus::Applied, ProvisioningStatus::AlreadyApplied], true);
    }

    public function isRestoring(): bool
    {
        return $this->last_restore_status === 'running';
    }

    /**
     * What the node needs to create this backup: the key and the account's resources on the node.
     *
     * @param  array{username: string, web_resources: list<string>, mail_domains: list<string>, databases: list<string>}  $scope
     * @return array{label: string|null, encryption_key: string, account: array<string, mixed>}
     */
    public function toCreatePayload(array $scope): array
    {
        return [
            'label' => $this->label,
            'encryption_key' => $this->encryption_key,
            'account' => $scope + ['parts' => $this->requested_parts],
        ];
    }

    /**
     * What the node needs to bring a backup file in as a sealed backup of this account: a fresh key
     * and a source, either a one-time address on the control plane or an object in the account's
     * own storage. The parts the file really holds are read from the file by the node.
     *
     * @param  array{username: string, web_resources: list<string>, mail_domains: list<string>, databases: list<string>}  $scope
     * @param  array<string, mixed>  $source  source_url, or destination and object_key
     * @return array<string, mixed>
     */
    public function toImportPayload(array $scope, array $source): array
    {
        return [
            'label' => $this->label,
            'encryption_key' => $this->encryption_key,
            'account' => $scope + ['parts' => self::PARTS],
        ] + $source;
    }

    /**
     * What the node needs to restore the chosen parts into the account's current resources.
     *
     * @param  array{username: string, web_resources: list<string>, mail_domains: list<string>, databases: list<string>}  $scope
     * @param  list<string>  $parts
     * @return array{artifact_path: string|null, encryption_key: string, account: array<string, mixed>}
     */
    public function toRestorePayload(array $scope, array $parts): array
    {
        return [
            'artifact_path' => $this->artifact_path,
            'encryption_key' => $this->encryption_key,
            'account' => $scope + ['parts' => $parts],
        ];
    }

    /**
     * What the node needs to stream this backup, decrypted, to a one-time upload address.
     *
     * @param  array{username: string, web_resources: list<string>, mail_domains: list<string>, databases: list<string>}  $scope
     * @return array{artifact_path: string|null, encryption_key: string, upload_url: string, account: array<string, mixed>}
     */
    public function toDownloadPayload(array $scope, string $uploadUrl): array
    {
        return [
            'artifact_path' => $this->artifact_path,
            'encryption_key' => $this->encryption_key,
            'upload_url' => $uploadUrl,
            'account' => $scope + ['parts' => $this->parts ?? $this->requested_parts],
        ];
    }

    /**
     * What the node needs to copy this backup, decrypted, to the account's storage.
     *
     * @param  array{username: string, web_resources: list<string>, mail_domains: list<string>, databases: list<string>}  $scope
     * @param  array{endpoint: string, region: string, bucket: string, access_key: string, secret_key: string}  $destination
     * @return array{artifact_path: string|null, encryption_key: string, destination: array<string, string>, object_key: string, account: array<string, mixed>}
     */
    public function toCopyPayload(array $scope, array $destination, string $objectKey): array
    {
        return [
            'artifact_path' => $this->artifact_path,
            'encryption_key' => $this->encryption_key,
            'destination' => $destination,
            'object_key' => $objectKey,
            'account' => $scope + ['parts' => $this->parts ?? $this->requested_parts],
        ];
    }

    /**
     * @return array{artifact_path: string|null}
     */
    public function toDeletePayload(): array
    {
        return ['artifact_path' => $this->artifact_path];
    }
}
