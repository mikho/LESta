<?php

namespace App\Models;

use Database\Factories\AccountBackupDestinationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An account's own S3-compatible storage (AWS S3, MinIO, Backblaze B2, Wasabi, Cloudflare R2, ...)
 * that its backups are copied to, decrypted, as a plain tar.gz after they are made. The keys are
 * encrypted at rest and never shown again after they are saved. Copies are never deleted by LESta:
 * retention in the bucket is the owner's (a lifecycle rule on the bucket).
 *
 * @property int $id
 * @property int $account_id
 * @property bool $enabled
 * @property string $endpoint
 * @property string $region
 * @property string $bucket
 * @property string $prefix
 * @property string $access_key
 * @property string $secret_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['account_id', 'enabled', 'endpoint', 'region', 'bucket', 'prefix', 'access_key', 'secret_key'])]
#[Hidden(['access_key', 'secret_key'])]
class AccountBackupDestination extends Model
{
    /** @use HasFactory<AccountBackupDestinationFactory> */
    use HasFactory;

    /** The same patterns the node enforces. */
    public const string REGION_PATTERN = '/^[a-z0-9-]{2,30}$/';

    public const string BUCKET_PATTERN = '/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/';

    public const string KEY_PATTERN = '/^[A-Za-z0-9._-]{4,128}$/';

    public const string SECRET_PATTERN = '/^[A-Za-z0-9\/+=._-]{8,256}$/';

    public const string PREFIX_PATTERN = "/^[A-Za-z0-9!_.*'()\/-]{0,100}$/";

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'access_key' => 'encrypted', 'secret_key' => 'encrypted'];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * What the node needs to reach the storage.
     *
     * @return array{endpoint: string, region: string, bucket: string, access_key: string, secret_key: string}
     */
    public function toPayload(): array
    {
        return [
            'endpoint' => rtrim($this->endpoint, '/'),
            'region' => $this->region,
            'bucket' => $this->bucket,
            'access_key' => $this->access_key,
            'secret_key' => $this->secret_key,
        ];
    }
}
