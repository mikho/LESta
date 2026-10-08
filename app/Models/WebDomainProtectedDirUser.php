<?php

namespace App\Models;

use App\Concerns\HasUuid;
use Database\Factories\WebDomainProtectedDirUserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One login of a protected directory. Only a SHA-512 crypt hash is ever stored (the format nginx's
 * auth_basic verifies); the password itself is never kept and the hash never leaves the control
 * plane except in the payload to the node that renders it.
 *
 * @property int $id
 * @property string $uuid
 * @property int $protected_dir_id
 * @property string $username
 * @property string $password_hash
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['protected_dir_id', 'username', 'password_hash'])]
#[Hidden(['password_hash'])]
class WebDomainProtectedDirUser extends Model
{
    /** @use HasFactory<WebDomainProtectedDirUserFactory> */
    use HasFactory, HasUuid;

    /** What the node agent accepts: SHA-512 crypt, with an optional rounds field. */
    public const string HASH_PATTERN = '/^\$6\$(?:rounds=[0-9]{1,9}\$)?[A-Za-z0-9.\/]{1,16}\$[A-Za-z0-9.\/]{86}$/';

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return BelongsTo<WebDomainProtectedDir, $this>
     */
    public function directory(): BelongsTo
    {
        return $this->belongsTo(WebDomainProtectedDir::class, 'protected_dir_id');
    }

    /**
     * A SHA-512 crypt hash of the password with a fresh random salt.
     */
    public static function hashPassword(string $password): string
    {
        $alphabet = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $salt = '';

        for ($i = 0; $i < 16; $i++) {
            $salt .= $alphabet[random_int(0, 63)];
        }

        return crypt($password, '$6$rounds=5000$'.$salt.'$');
    }
}
