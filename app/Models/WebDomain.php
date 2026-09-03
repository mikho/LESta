<?php

namespace App\Models;

use App\Concerns\HasUuid;
use App\Concerns\Suspendable;
use App\Enums\SslMode;
use App\Enums\SuspensionSource;
use App\Enums\WebServer;
use Database\Factories\WebDomainFactory;
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
 * @property int $ip_allocation_id
 * @property string $domain
 * @property string $web_template
 * @property WebServer $web_server
 * @property SslMode $ssl_mode
 * @property string|null $certificate_authority
 * @property Carbon|null $certificate_issued_at
 * @property Carbon|null $certificate_expires_at
 * @property string|null $last_certificate_error
 * @property int $desired_state_version
 * @property Carbon|null $suspended_at
 * @property SuspensionSource|null $suspension_source
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['account_id', 'node_id', 'ip_allocation_id', 'domain', 'web_template', 'web_server', 'ssl_mode', 'certificate_authority', 'certificate_issued_at', 'certificate_expires_at', 'last_certificate_error', 'desired_state_version'])]
class WebDomain extends Model
{
    /** @use HasFactory<WebDomainFactory> */
    use HasFactory, HasUuid, Suspendable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'web_server' => WebServer::class,
            'ssl_mode' => SslMode::class,
            'certificate_issued_at' => 'datetime',
            'certificate_expires_at' => 'datetime',
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
     * @return BelongsTo<IpAllocation, $this>
     */
    public function ipAllocation(): BelongsTo
    {
        return $this->belongsTo(IpAllocation::class);
    }

    /**
     * @return HasMany<WebDomainAlias, $this>
     */
    public function aliases(): HasMany
    {
        return $this->hasMany(WebDomainAlias::class);
    }

    /**
     * The single most recent provisioning operation for this domain. `MorphMany::latestOfMany()`
     * does not exist in this Laravel version (only `HasOne`/`MorphOne`/`HasOneThrough` support
     * the "of many" relation subquery); `morphOne()->latestOfMany()` is the idiomatic equivalent
     * and returns the exact same single-row semantics the Phase 2 plan intends.
     *
     * @return MorphOne<ProvisioningOperation, $this>
     */
    public function latestProvisioningOperation(): MorphOne
    {
        return $this->morphOne(ProvisioningOperation::class, 'provisionable')->latestOfMany();
    }

    /**
     * Resolve the DnsZone that answers for this domain, if any: exact-match only this phase
     * (`WebDomain::normalizeDomain($this->domain)` against `dns_zones.domain`), no parent-label
     * walking. Deliberately narrow, matching this project's own "don't build for a case with no
     * evidence yet" pattern: if no zone exists, DNS-01 issuance simply isn't offered for this
     * domain (HTTP-01 still is), a known limitation rather than a silently-glossed-over one.
     */
    public function resolveDnsZone(): ?DnsZone
    {
        return DnsZone::query()->where('domain', self::normalizeDomain($this->domain))->first();
    }

    /**
     * Shape the desired-state payload sent to a provisioner for a specific capability. Never
     * anything secret-shaped (a domain's own issued certificate/private key is not secret-shaped
     * in the ADR's sense -- it must reach the node's disk in cleartext for nginx to terminate TLS
     * with it; only the ACME *account* key that signs requests to the CA is forbidden here, and
     * that key never appears in any Eloquent attribute this method could reach). When $capability
     * is the nginx capability and this domain's web_server is apache (the "both" profile's proxy
     * leg), web_template is overridden to the fixed sentinel 'apache-proxy' regardless of the
     * domain's own stored web_template: nginx is not rendering the tenant's own content in that
     * case, only proxying to whichever node capability actually renders it. Every other
     * combination is unchanged.
     *
     * ssl.certificate_path/private_key_path are populated for both web.nginx.v1 and web.apache.v1,
     * only once certificate_issued_at is set: until an ACME issuance has actually succeeded, a web
     * capability must keep rendering HTTP-only (see internal/capability/nginx's and
     * internal/capability/apache's own SSL doc comments for why). Apache only ever receives an
     * SSL-bearing payload when it is genuinely this domain's resolved public capability (nginx
     * always wins in the "both" profile, per ResolvesWebCapableNode's own priority), so this never
     * needs a web_server-based guard of its own.
     *
     * @return array{domain: string, aliases: array<int, string>, ip_address: string, web_template: string, ssl: array{mode: string, certificate_path?: string, private_key_path?: string}, suspended: bool}
     */
    public function toProvisioningPayload(string $capability): array
    {
        $webTemplate = $this->web_template;

        if ($capability === 'web.nginx.v1' && $this->web_server === WebServer::Apache) {
            $webTemplate = 'apache-proxy';
        }

        $ssl = ['mode' => $this->ssl_mode->value];

        if (in_array($capability, ['web.nginx.v1', 'web.apache.v1'], true) && $this->certificate_issued_at !== null) {
            $ssl['certificate_path'] = "/var/lib/lesta/acme/certs/{$this->domain}/fullchain.pem";
            $ssl['private_key_path'] = "/var/lib/lesta/acme/certs/{$this->domain}/privkey.pem";
        }

        return [
            'domain' => $this->domain,
            'aliases' => $this->aliases()->pluck('alias')->all(),
            'ip_address' => $this->ipAllocation->ip_address,
            'web_template' => $webTemplate,
            'ssl' => $ssl,
            'suspended' => $this->isSuspended(),
        ];
    }
}
