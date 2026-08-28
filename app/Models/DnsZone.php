<?php

namespace App\Models;

use App\Concerns\HasUuid;
use App\Concerns\Suspendable;
use App\Enums\SuspensionSource;
use Database\Factories\DnsZoneFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $account_id
 * @property int $node_id
 * @property string $domain
 * @property int $ttl
 * @property int $desired_state_version
 * @property Carbon|null $suspended_at
 * @property SuspensionSource|null $suspension_source
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['account_id', 'node_id', 'domain', 'ttl', 'desired_state_version'])]
class DnsZone extends Model
{
    /** @use HasFactory<DnsZoneFactory> */
    use HasFactory, HasUuid, Suspendable;

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
     * Route model binding resolves by uuid, not the internal auto-increment id.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Normalize a domain name to its canonical ASCII/punycode form: lowercased, trimmed, and
     * IDN-converted. Falls back to the trimmed, lowercased input if conversion fails (e.g. the
     * input is already ASCII, or is not a valid IDN).
     *
     * Deliberately duplicated from WebDomain::normalizeDomain() rather than extracted into a
     * shared trait (rule of three; only two call sites exist today).
     */
    public static function normalizeDomain(string $domain): string
    {
        $trimmed = mb_strtolower(trim($domain));

        $converted = idn_to_ascii($trimmed, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        return $converted === false ? $trimmed : $converted;
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
     * @return HasMany<DnsRecord, $this>
     */
    public function records(): HasMany
    {
        return $this->hasMany(DnsRecord::class);
    }

    /**
     * The single most recent provisioning operation for this zone. `MorphMany::latestOfMany()`
     * does not exist in this Laravel version (only `HasOne`/`MorphOne`/`HasOneThrough` support
     * the "of many" relation subquery); `morphOne()->latestOfMany()` is the idiomatic equivalent.
     *
     * @return MorphOne<ProvisioningOperation, $this>
     */
    public function latestProvisioningOperation(): MorphOne
    {
        return $this->morphOne(ProvisioningOperation::class, 'provisionable')->latestOfMany();
    }

    /**
     * Shape the desired-state payload sent to a provisioner. Never anything secret-shaped.
     *
     * @return array{domain: string, ttl: int, records: array<int, array{name: string, type: string, priority: int|null, value: string, suspended: bool}>, suspended: bool}
     */
    public function toProvisioningPayload(): array
    {
        return [
            'domain' => $this->domain,
            'ttl' => $this->ttl,
            'records' => $this->records()->get()->map(fn (DnsRecord $r) => [
                'name' => $r->name,
                'type' => $r->type->value,
                'priority' => $r->priority,
                'value' => $r->value,
                'suspended' => $r->isSuspended(),
            ])->all(),
            'suspended' => $this->isSuspended(),
        ];
    }
}
