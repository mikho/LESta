<?php

namespace App\Models;

use App\Concerns\HasUuid;
use App\Enums\DatabaseUserAccess;
use Database\Factories\TenantDatabaseUserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An extra MariaDB user on one tenant database, beside the database's own
 * user and its statistics account: full or read-only access. It travels in
 * its database's payload (TenantDatabase::toProvisioningPayload), so it is
 * suspended, unsuspended and dropped with the database. Its password is
 * generated, sent to the node once (on creation or a rotation) and shown to
 * the customer once; the panel never stores it, since nothing here needs it
 * again (Adminer signs in as the database's own user).
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_database_id
 * @property string $label
 * @property string $username
 * @property DatabaseUserAccess $access
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['tenant_database_id', 'label', 'username', 'access'])]
class TenantDatabaseUser extends Model
{
    /** @use HasFactory<TenantDatabaseUserFactory> */
    use HasFactory, HasUuid;

    /**
     * The most extra users one database carries; the agent refuses more.
     */
    public const MAX_PER_DATABASE = 20;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access' => DatabaseUserAccess::class,
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
     * The MariaDB user name: the database's name, an underscore and the
     * label, the shape the agent checks (extraUserSuffixPattern).
     */
    public static function deriveUsername(TenantDatabase $tenantDatabase, string $label): string
    {
        return "{$tenantDatabase->database_name}_{$label}";
    }

    /**
     * @return BelongsTo<TenantDatabase, $this>
     */
    public function tenantDatabase(): BelongsTo
    {
        return $this->belongsTo(TenantDatabase::class);
    }
}
