<?php

namespace App\Models;

use App\Concerns\HasUuid;
use Database\Factories\AccountBackupImportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A backup file the owner is uploading from their own computer to restore from. The browser sends
 * it in chunks; once complete it is kept on the control plane only until the node has fetched it
 * (at a one-time address identified by a random token, only its hash is stored) and sealed it as an
 * ordinary AccountBackup. The file and row are removed when that finishes, or when it expires.
 *
 * @property int $id
 * @property string $uuid
 * @property int $account_id
 * @property int $node_id
 * @property int|null $account_backup_id
 * @property string|null $label
 * @property string|null $token_hash
 * @property string $status uploading, ready, used or failed
 * @property string|null $path
 * @property int $size_bytes
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['account_id', 'node_id', 'account_backup_id', 'label', 'token_hash', 'status', 'path', 'size_bytes', 'expires_at'])]
#[Hidden(['token_hash'])]
class AccountBackupImport extends Model
{
    /** @use HasFactory<AccountBackupImportFactory> */
    use HasFactory, HasUuid;

    /** The largest file that can be imported (4 GB), the same as a download. */
    public const int MAX_BYTES = 4 * 1024 * 1024 * 1024;

    /** Hours an upload may take, and a finished one may wait for its node. */
    public const int KEEP_HOURS = 3;

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
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

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
