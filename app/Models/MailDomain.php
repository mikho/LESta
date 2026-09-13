<?php

namespace App\Models;

use App\Concerns\HasUuid;
use App\Concerns\Suspendable;
use App\Enums\SuspensionSource;
use Database\Factories\MailDomainFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * A tenant-owned mail domain, the single provisioning resource for
 * mail.smtp-imap.v1 (one resource per domain, every account embedded into
 * its own toProvisioningPayload(), mirroring DnsZone's own zone-embeds-
 * records precedent -- both render one artifact per domain on the real
 * node, a real Exim virtual-domain config block for mail, a real zone file
 * for DNS). See the vault's "Mail Threat Model and Operational Readiness
 * Review" for why antivirus_enabled/antispam_enabled default true while
 * dkim_enabled defaults false, and for why this phase deliberately stops at
 * the relational foundation: no real Exim/Dovecot/DKIM capability exists
 * yet, only the model, policy, quota, and fake-provisioning wiring.
 *
 * @property int $id
 * @property string $uuid
 * @property int $account_id
 * @property int $node_id
 * @property string $domain
 * @property bool $antivirus_enabled
 * @property bool $antispam_enabled
 * @property bool $dkim_enabled
 * @property string $dkim_selector
 * @property Carbon|null $dkim_selector_activated_at
 * @property string|null $dkim_pending_selector
 * @property Carbon|null $dkim_pending_selector_published_at
 * @property string|null $dkim_retiring_selector
 * @property Carbon|null $dkim_retiring_selector_demoted_at
 * @property string|null $catchall_email
 * @property int $desired_state_version
 * @property Carbon|null $suspended_at
 * @property SuspensionSource|null $suspension_source
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['account_id', 'node_id', 'domain', 'antivirus_enabled', 'antispam_enabled', 'dkim_enabled', 'dkim_selector', 'dkim_selector_activated_at', 'dkim_pending_selector', 'dkim_pending_selector_published_at', 'dkim_retiring_selector', 'dkim_retiring_selector_demoted_at', 'catchall_email', 'desired_state_version'])]
class MailDomain extends Model
{
    /** @use HasFactory<MailDomainFactory> */
    use HasFactory, HasUuid, Suspendable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'antivirus_enabled' => 'boolean',
            'antispam_enabled' => 'boolean',
            'dkim_enabled' => 'boolean',
            'dkim_selector_activated_at' => 'datetime',
            'dkim_pending_selector_published_at' => 'datetime',
            'dkim_retiring_selector_demoted_at' => 'datetime',
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
     * IDN-converted. Deliberately duplicated from WebDomain::normalizeDomain()/
     * DnsZone::normalizeDomain() rather than extracted into a shared trait, matching this
     * project's own established rule-of-three precedent for this exact method.
     */
    public static function normalizeDomain(string $domain): string
    {
        $trimmed = mb_strtolower(trim($domain));

        $converted = idn_to_ascii($trimmed, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        return $converted === false ? $trimmed : $converted;
    }

    /**
     * The next selector name a rotation should generate, incrementing the trailing digit of the
     * current dkim_selector ("lesta1" -> "lesta2" -> "lesta3", ...). Selectors are never reused:
     * a fresh rotation always starts from whatever the currently active selector's own number is,
     * never a retired one's, so there is no risk of colliding with a selector whose own DNS
     * record/key retirement (see App\Console\Commands\RetiresOldDkimSelectors) has not finished
     * yet -- App\Console\Commands\RotateDkimSelectors refuses to start a new rotation while one is
     * already in progress in the first place.
     */
    public function nextDkimSelector(): string
    {
        if (! preg_match('/^(.*?)(\d+)$/', $this->dkim_selector, $matches)) {
            throw new RuntimeException("dkim_selector [{$this->dkim_selector}] does not end in a digit; cannot compute the next selector.");
        }

        return $matches[1].((int) $matches[2] + 1);
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
     * @return HasMany<MailAccount, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(MailAccount::class);
    }

    /**
     * The single most recent provisioning operation for this domain. `MorphMany::latestOfMany()`
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
     * Shape the desired-state payload sent to a provisioner. $includePasswordForAccountId and
     * $plaintextPassword are explicit, never implicit, mirroring TenantDatabase::
     * toProvisioningPayload()'s own invariant: this method never decrypts any account's stored
     * password, so a call site can only ever include one account's password by deliberately
     * passing the plaintext it just generated or rotated in the very same request. Every other
     * account embedded here never carries a 'password' key at all (not merely null), keeping the
     * ADR's "database/mail credentials are never included in normal desired-state payloads"
     * restriction true even though this payload embeds every sibling account.
     *
     * $retireSelector mirrors that same explicit-parameter discipline for a different reason: it
     * is a one-shot instruction ("delete this selector's key material now"), never standing
     * desired state the way dkim_selector/dkim_pending_selector are, so it is never read from a
     * stored column here -- only App\Console\Commands\RetireOldDkimSelectors ever passes it, for
     * exactly the one apply that actually performs a retirement.
     *
     * @return array{domain: string, antivirus_enabled: bool, antispam_enabled: bool, dkim_enabled: bool, dkim_active_selector: string, dkim_pending_selector: string|null, dkim_retire_selector: string|null, catchall_email: string|null, accounts: array<int, array{local_part: string, password?: string, quota_mb: int|null, forward_to: string|null, forward_only: bool, autoreply_enabled: bool, autoreply_message: string|null, suspended: bool}>, suspended: bool}
     */
    public function toProvisioningPayload(?int $includePasswordForAccountId = null, ?string $plaintextPassword = null, ?string $retireSelector = null): array
    {
        return [
            'domain' => $this->domain,
            'antivirus_enabled' => $this->antivirus_enabled,
            'antispam_enabled' => $this->antispam_enabled,
            'dkim_enabled' => $this->dkim_enabled,
            'dkim_active_selector' => $this->dkim_selector,
            'dkim_pending_selector' => $this->dkim_pending_selector,
            'dkim_retire_selector' => $retireSelector,
            'catchall_email' => $this->catchall_email,
            'accounts' => $this->accounts()->get()->map(function (MailAccount $a) use ($includePasswordForAccountId, $plaintextPassword): array {
                $account = [
                    'local_part' => $a->local_part,
                ];

                if ($includePasswordForAccountId === $a->id) {
                    $account['password'] = $plaintextPassword;
                }

                $account['quota_mb'] = $a->quota_mb;
                $account['forward_to'] = $a->forward_to;
                $account['forward_only'] = $a->forward_only;
                $account['autoreply_enabled'] = $a->autoreply_enabled;
                $account['autoreply_message'] = $a->autoreply_message;
                $account['suspended'] = $a->isSuspended();

                return $account;
            })->all(),
            'suspended' => $this->isSuspended(),
        ];
    }
}
