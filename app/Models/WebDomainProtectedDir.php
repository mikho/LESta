<?php

namespace App\Models;

use App\Concerns\HasUuid;
use Database\Factories\WebDomainProtectedDirFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A directory of a web domain that asks for a login (nginx auth_basic) with its own list of users.
 * Path is stored without a trailing slash and is never "/" itself.
 *
 * @property int $id
 * @property string $uuid
 * @property int $web_domain_id
 * @property string $path
 * @property string $realm
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['web_domain_id', 'path', 'realm'])]
class WebDomainProtectedDir extends Model
{
    /** @use HasFactory<WebDomainProtectedDirFactory> */
    use HasFactory, HasUuid;

    public const int MAX_PER_DOMAIN = 20;

    public const int MAX_USERS = 50;

    /** The same patterns the node agent enforces. */
    public const string PATH_PATTERN = '/^\/[A-Za-z0-9._~%+\/-]{1,198}$/';

    public const string REALM_PATTERN = '/^[A-Za-z0-9 .,_-]{1,64}$/';

    public const string USERNAME_PATTERN = '/^[A-Za-z0-9._-]{1,32}$/';

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return BelongsTo<WebDomain, $this>
     */
    public function webDomain(): BelongsTo
    {
        return $this->belongsTo(WebDomain::class);
    }

    /**
     * @return HasMany<WebDomainProtectedDirUser, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(WebDomainProtectedDirUser::class, 'protected_dir_id');
    }
}
