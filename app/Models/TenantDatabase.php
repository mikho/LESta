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
 * Also carries a companion least-privilege read-only account (stats_user/
 * stats_password), per the Foundations decision log: "statistics reads
 * tenant-database state directly, through a dedicated least-privilege
 * read-only database account, in addition to web logs, not web logs
 * alone." It is provisioned, suspended, and dropped in lockstep with the
 * tenant's own account (one resource, one lifecycle -- see
 * database.tenant.v1's own exec.go for the actual GRANT/REVOKE DDL), and its
 * password rotates alongside the tenant's own whenever
 * RotateTenantDatabasePassword runs, never on an independent schedule.
 *
 * @property int $id
 * @property string $uuid
 * @property int $account_id
 * @property int $node_id
 * @property string $label
 * @property string $database_name
 * @property string $database_user
 * @property string $password
 * @property string $stats_user
 * @property string $stats_password
 * @property int $desired_state_version
 * @property Carbon|null $suspended_at
 * @property SuspensionSource|null $suspension_source
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['account_id', 'node_id', 'label', 'database_name', 'database_user', 'password', 'stats_user', 'stats_password', 'desired_state_version'])]
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
            'stats_password' => 'encrypted',
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
     * Derive the companion read-only statistics account's username from an
     * already-derived database_name/database_user: the same identifier with
     * a literal "_ro" suffix, never derived independently (the Go
     * database.tenant.v1 capability's own statsIdentifierPattern enforces
     * this exact relationship on the wire, defense in depth against this
     * method ever drifting from it). MariaDB's own username length limit is
     * 128 characters (since 10.4, well before this project's pinned 11.4),
     * comfortably wider than the 64-character general identifier limit
     * deriveDatabaseName already enforces for its own input, so the "_ro"
     * suffix can never overflow it.
     */
    public static function deriveStatsUsername(string $databaseName): string
    {
        $name = "{$databaseName}_ro";

        if (mb_strlen($name) > 128) {
            throw new InvalidArgumentException("Derived stats username [{$name}] exceeds MariaDB's 128-character username limit.");
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
     * Shape the desired-state payload sent to a provisioner. $includePassword,
     * $plaintextPassword, and $statsPlaintextPassword are explicit, never
     * implicit: this method never decrypts $this->password or
     * $this->stats_password itself, so a call site can only ever include a
     * credential by deliberately passing the plaintext it just generated or
     * rotated in the very same request -- keeping the ADR's "database
     * credentials are never included in normal desired-state payloads"
     * restriction visible and enforced at every call site, not just trusted
     * by convention. Every verb except create and the dedicated password-
     * rotate operation calls this with no arguments, so 'password'/
     * 'stats_password' are genuinely absent from the encoded payload (not
     * merely null) for suspend/unsuspend/delete/observe. stats_user, unlike
     * stats_password, is always present: every verb's DDL needs to name the
     * companion statistics account, not just create/update (mirroring
     * database_user).
     *
     * @return array{database_name: string, database_user: string, password?: string, stats_user: string, stats_password?: string, suspended: bool}
     */
    public function toProvisioningPayload(bool $includePassword = false, ?string $plaintextPassword = null, ?string $statsPlaintextPassword = null): array
    {
        if ($includePassword && $statsPlaintextPassword === null) {
            throw new InvalidArgumentException('statsPlaintextPassword must be provided whenever includePassword is true: the two accounts rotate in lockstep.');
        }

        $payload = [
            'database_name' => $this->database_name,
            'database_user' => $this->database_user,
        ];

        if ($includePassword) {
            $payload['password'] = $plaintextPassword;
        }

        $payload['stats_user'] = $this->stats_user;

        if ($includePassword) {
            $payload['stats_password'] = $statsPlaintextPassword;
        }

        $payload['suspended'] = $this->isSuspended();

        return $payload;
    }
}
