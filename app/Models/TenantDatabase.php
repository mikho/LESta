<?php

namespace App\Models;

use App\Concerns\HasUuid;
use App\Concerns\Suspendable;
use App\Enums\SuspensionSource;
use Database\Factories\TenantDatabaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * A tenant-owned MariaDB database + matching database user, provisioned
 * against the tenant MariaDB instance (database.tenant.v1, port 3307; see
 * .install/services/mariadb). database_name/database_user are immutable
 * after creation (see app/Actions/TenantDatabases's own package doc comment
 * for why there is no generic Update action): password is the only mutable
 * field, and it gets its own dedicated RotateTenantDatabasePassword action
 * rather than folding into a generic update.
 *
 * @property int $id
 * @property string $uuid
 * @property int $account_id
 * @property int $node_id
 * @property string $label
 * @property string $database_name
 * @property string $database_user
 * @property string $password
 * @property int $desired_state_version
 * @property Carbon|null $suspended_at
 * @property SuspensionSource|null $suspension_source
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['account_id', 'node_id', 'label', 'database_name', 'database_user', 'password', 'desired_state_version'])]
class TenantDatabase extends Model
{
    /** @use HasFactory<TenantDatabaseFactory> */
    use HasFactory, HasUuid, Suspendable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'suspended_at' => 'datetime',
            'suspension_source' => SuspensionSource::class,
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
     * Derive the database_name (and, identically, database_user) for a new
     * tenant database from the owning account's numeric id and the
     * tenant-supplied label: "lesta_{$account->id}_{$label}". Account has no
     * slug/username field, so the always-unique, numeric account_id is the
     * namespacing prefix, not a free-text field. label is already validated
     * against ^[a-z][a-z0-9_]{0,32}$ by StoreTenantDatabaseRequest, so the
     * derived name is comfortably under MariaDB's 64-character identifier
     * cap; still defensively length-checked here rather than trusted blindly.
     */
    public static function deriveDatabaseName(int $accountId, string $label): string
    {
        $name = "lesta_{$accountId}_{$label}";

        if (mb_strlen($name) > 64) {
            throw new InvalidArgumentException("Derived database name [{$name}] exceeds MariaDB's 64-character identifier limit.");
        }

        return $name;
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
     * The single most recent provisioning operation for this database.
     * `MorphMany::latestOfMany()` does not exist in this Laravel version
     * (only `HasOne`/`MorphOne`/`HasOneThrough` support the "of many"
     * relation subquery); `morphOne()->latestOfMany()` is the idiomatic
     * equivalent.
     *
     * @return MorphOne<ProvisioningOperation, $this>
     */
    public function latestProvisioningOperation(): MorphOne
    {
        return $this->morphOne(ProvisioningOperation::class, 'provisionable')->latestOfMany();
    }

    /**
     * Shape the desired-state payload sent to a provisioner. $includePassword
     * and $plaintextPassword are explicit, never implicit: this method never
     * decrypts $this->password itself, so a call site can only ever include
     * a password by deliberately passing the plaintext it just generated or
     * rotated in the very same request -- keeping the ADR's "database
     * credentials are never included in normal desired-state payloads"
     * restriction visible and enforced at every call site, not just trusted
     * by convention. Every verb except create and the dedicated password-
     * rotate operation calls this with no arguments, so 'password' is
     * genuinely absent from the encoded payload (not merely null) for
     * suspend/unsuspend/delete/observe.
     *
     * @return array{database_name: string, database_user: string, password?: string, suspended: bool}
     */
    public function toProvisioningPayload(bool $includePassword = false, ?string $plaintextPassword = null): array
    {
        $payload = [
            'database_name' => $this->database_name,
            'database_user' => $this->database_user,
        ];

        if ($includePassword) {
            $payload['password'] = $plaintextPassword;
        }

        $payload['suspended'] = $this->isSuspended();

        return $payload;
    }
}
