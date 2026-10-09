<?php

namespace App\Models;

use App\Concerns\HasUuid;
use Database\Factories\AccountBackupDownloadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A decrypted copy of an account backup, prepared for the owner to download: the node streams the
 * backup to the control plane's one-time upload address (identified by a random token, only its hash
 * is stored), the control plane keeps the file for a short time, and the owner downloads it from the
 * panel. The file and row are removed when it expires.
 *
 * @property int $id
 * @property string $uuid
 * @property int $account_backup_id
 * @property string $token_hash
 * @property string $status pending, ready or failed
 * @property string|null $path
 * @property int $size_bytes
 * @property string|null $error_message
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['account_backup_id', 'token_hash', 'status', 'path', 'size_bytes', 'error_message', 'expires_at'])]
#[Hidden(['token_hash'])]
class AccountBackupDownload extends Model
{
    /** @use HasFactory<AccountBackupDownloadFactory> */
    use HasFactory, HasUuid;

    /** The largest backup that can be prepared for download (4 GB). */
    public const int MAX_BYTES = 4 * 1024 * 1024 * 1024;

    /** Minutes a ready download stays available. */
    public const int READY_MINUTES = 60;

    /** Minutes a download may take to be uploaded by the node. */
    public const int PREPARE_MINUTES = 120;

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return BelongsTo<AccountBackup, $this>
     */
    public function backup(): BelongsTo
    {
        return $this->belongsTo(AccountBackup::class, 'account_backup_id');
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function isReady(): bool
    {
        return $this->status === 'ready' && $this->expires_at->isFuture();
    }
}
