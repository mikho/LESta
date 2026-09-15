<?php

namespace App\Models;

use App\Concerns\Suspendable;
use App\Enums\SuspensionSource;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string|null $contact_email
 * @property int $package_id
 * @property int|null $reseller_account_id
 * @property Carbon|null $suspended_at
 * @property SuspensionSource|null $suspension_source
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'contact_email', 'package_id', 'reseller_account_id'])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory, Suspendable;

    protected static function booted(): void
    {
        static::creating(function (self $account): void {
            $account->public_id ??= self::generateUniquePublicId();
        });
    }

    /**
     * Twelve random characters, deliberately never derived from (or correlated with) this row's
     * own auto-increment id: an account's own id must never be a value worth guessing, since
     * finding one this way would let an attacker enumerate every account on the server before
     * ever attempting a single password. A dedicated column rather than the generic HasUuid trait
     * every other model uses, since a full v4 UUID is unnecessary entropy for this specific
     * threat model and a shorter opaque token is deliberately preferred here. The collision-retry
     * loop guards real correctness (a 12-character space is still enormous, but meaningfully
     * smaller than a UUID's), not just theoretical caution.
     */
    private static function generateUniquePublicId(): string
    {
        do {
            $candidate = Str::random(12);
        } while (self::query()->where('public_id', $candidate)->exists());

        return $candidate;
    }

    /**
     * Route model binding resolves by public_id, not the internal auto-increment id, matching
     * every other admin-managed resource's own route key convention (Node, Backup, WebDomain,
     * TenantDatabase, ...) in spirit, though this specific column is intentionally its own
     * dedicated 12-character token rather than the shared HasUuid trait -- see
     * generateUniquePublicId's own doc comment.
     */
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'suspended_at' => 'datetime',
            'suspension_source' => SuspensionSource::class,
        ];
    }

    /**
     * @return BelongsTo<Package, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * @return HasMany<WebDomain, $this>
     */
    public function webDomains(): HasMany
    {
        return $this->hasMany(WebDomain::class);
    }

    /**
     * @return HasMany<DnsZone, $this>
     */
    public function dnsZones(): HasMany
    {
        return $this->hasMany(DnsZone::class);
    }

    /**
     * @return HasMany<TenantDatabase, $this>
     */
    public function tenantDatabases(): HasMany
    {
        return $this->hasMany(TenantDatabase::class);
    }

    /**
     * @return HasMany<CronJob, $this>
     */
    public function cronJobs(): HasMany
    {
        return $this->hasMany(CronJob::class);
    }

    /**
     * @return HasMany<UsageSnapshot, $this>
     */
    public function usageSnapshots(): HasMany
    {
        return $this->hasMany(UsageSnapshot::class);
    }

    /**
     * @return HasMany<UsageSnapshotRollup, $this>
     */
    public function usageSnapshotRollups(): HasMany
    {
        return $this->hasMany(UsageSnapshotRollup::class);
    }

    /**
     * @return HasMany<MailDomain, $this>
     */
    public function mailDomains(): HasMany
    {
        return $this->hasMany(MailDomain::class);
    }

    /**
     * The reseller account that manages this account, if any (see reseller_account_id's own
     * migration doc comment: one level only, a reseller is just an ordinary Account, no new
     * top-level model).
     *
     * @return BelongsTo<Account, $this>
     */
    public function resellerAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'reseller_account_id');
    }

    /**
     * Every account this one manages as a reseller. Only ever populated on an account that is
     * itself acting as a reseller; an ordinary account's own managedAccounts is always empty.
     *
     * @return HasMany<Account, $this>
     */
    public function managedAccounts(): HasMany
    {
        return $this->hasMany(Account::class, 'reseller_account_id');
    }
}
