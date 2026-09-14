<?php

namespace App\Models;

use App\Concerns\HasUuid;
use App\Concerns\Suspendable;
use App\Enums\SuspensionSource;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
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
    use HasFactory, HasUuid, Suspendable;

    /**
     * Route model binding resolves by uuid, not the internal auto-increment id, matching every
     * other admin-managed resource's own route key convention (Node, Backup, WebDomain,
     * TenantDatabase, ...). Account already had a real uuid column but had never actually been
     * wired as the route key until the admin account page needed one -- no route bound {account}
     * at all before this.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
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
