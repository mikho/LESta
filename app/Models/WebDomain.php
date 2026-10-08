<?php

namespace App\Models;

use App\Concerns\HasUuid;
use App\Concerns\Suspendable;
use App\Enums\NodeCapabilityType;
use App\Enums\PhpVersion;
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
use RuntimeException;

/**
 * @property int $id
 * @property string $uuid
 * @property int $account_id
 * @property int $node_id
 * @property int $ip_allocation_id
 * @property string $domain
 * @property string $web_template
 * @property WebServer $web_server
 * @property PhpVersion|null $php_version
 * @property SslMode $ssl_mode
 * @property string $waf_mode
 * @property list<int>|null $waf_excluded_rules
 * @property string $waf_preset
 * @property bool $hotlink_protection
 * @property list<string>|null $hotlink_allowed_hosts
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
#[Fillable(['account_id', 'node_id', 'ip_allocation_id', 'domain', 'web_template', 'web_server', 'php_version', 'ssl_mode', 'waf_mode', 'waf_excluded_rules', 'waf_preset', 'hotlink_protection', 'hotlink_allowed_hosts', 'certificate_authority', 'certificate_issued_at', 'certificate_expires_at', 'last_certificate_error', 'desired_state_version'])]
class WebDomain extends Model
{
    /** @use HasFactory<WebDomainFactory> */
    use HasFactory, HasUuid, Suspendable;

    /**
     * Mirrors the column default, so a model created in memory reads the same as one loaded.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['waf_mode' => 'off', 'waf_preset' => 'none', 'hotlink_protection' => false];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'web_server' => WebServer::class,
            'php_version' => PhpVersion::class,
            'ssl_mode' => SslMode::class,
            'waf_excluded_rules' => 'array',
            'hotlink_protection' => 'boolean',
            'hotlink_allowed_hosts' => 'array',
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
     * @return HasMany<WebDomainProtectedDir, $this>
     */
    public function protectedDirs(): HasMany
    {
        return $this->hasMany(WebDomainProtectedDir::class);
    }

    /**
     * @return HasMany<WebDomainRedirect, $this>
     */
    public function redirects(): HasMany
    {
        return $this->hasMany(WebDomainRedirect::class);
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
     * account_id/account_username/php_socket are new as of web.php-fpm.v1: account_username lets
     * web.nginx.v1 resolve this domain's own per-domain docroot
     * (AccountsRoot/{account_username}/domains/{resource_id}/public, resource_id being this
     * operation's own OperationEnvelope.ResourceID, i.e. this domain's uuid -- never recomputed
     * from anything tenant-supplied) itself, agent-side, exactly like every other filesystem path
     * this project already resolves on that side of the wire. Keyed by the account's own real
     * system username, not its numeric id, so the path lands inside that same account's own SFTP
     * chroot root (system.account-identity.v1's own AccountsRoot/{username}) -- account_id alone
     * would point nginx at a directory no SFTP session could ever reach. php_socket is null when
     * php_version is null (static-only, no FastCGI block rendered at all); otherwise it is the
     * exact same deterministic path web.php-fpm.v1's own pool listens on
     * (/run/lesta-php/{php_version}/{resource_id}.sock), computed here from the same fixed formula
     * both sides of the protocol share, never passed the other way (the pool config that actually
     * creates the socket is authoritative; this is just how the vhost knows where to find it).
     *
     * web.php-fpm.v1 itself receives a different shape entirely (account_username/php_version,
     * account_id for open_basedir scoping) via toPhpFpmProvisioningPayload() below, resolved
     * separately since it is only ever dispatched when php_version is actually set.
     *
     * @return array{domain: string, aliases: array<int, string>, ip_address: string, web_template: string, account_id: int, account_username: string, php_socket: string|null, waf_mode?: string, waf_excluded_rules?: list<int>, waf_preset?: string, hotlink_protection?: bool, hotlink_allowed_hosts?: list<string>, ip_rules?: list<array{action: string, cidr: string}>, redirects?: list<array{source: string, target: string, status: int, prefix: bool}>, adminer_socket: string|null, webmail_socket: string|null, ssl: array{mode: string, certificate_path?: string, private_key_path?: string}, suspended: bool}
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

        // Only web.nginx.v1 renders ModSecurity, hotlink protection and the account's IP rules; web.apache.v1 rejects unknown payload fields.
        $waf = $capability === 'web.nginx.v1'
            ? ['waf_mode' => $this->waf_mode, 'waf_excluded_rules' => ($this->waf_excluded_rules ?? []), 'waf_preset' => $this->waf_preset, 'hotlink_protection' => $this->hotlink_protection, 'hotlink_allowed_hosts' => ($this->hotlink_allowed_hosts ?? []), 'redirects' => array_values($this->redirects()->orderBy('id')->get()->map(fn (WebDomainRedirect $redirect): array => ['source' => $redirect->source, 'target' => $redirect->target, 'status' => $redirect->status, 'prefix' => $redirect->prefix])->all()), 'protected_dirs' => array_values($this->protectedDirs()->with('users')->orderBy('id')->get()->map(fn (WebDomainProtectedDir $dir): array => ['path' => $dir->path, 'realm' => $dir->realm, 'users' => array_values($dir->users->sortBy('id')->map(fn (WebDomainProtectedDirUser $user): array => ['username' => $user->username, 'hash' => $user->password_hash])->all())])->all()), 'ip_rules' => array_values($this->account->ipRules()->orderBy('id')->get(['action', 'cidr'])->map(fn (AccountIpRule $rule): array => ['action' => $rule->action, 'cidr' => $rule->cidr])->all())]
            : [];

        return [
            'domain' => $this->domain,
            'aliases' => $this->aliases()->pluck('alias')->all(),
            'ip_address' => $this->ipAllocation->ip_address,
            'web_template' => $webTemplate,
            'account_id' => $this->account_id,
            'account_username' => $this->resolveAccountUsername(),
            'php_socket' => $this->phpSocketPath(),
            'adminer_socket' => $this->resolveAdminerSocket($capability),
            'webmail_socket' => $this->resolveWebmailSocket($capability),
            'ssl' => $ssl,
            'suspended' => $this->isSuspended(),
        ] + $waf;
    }

    /**
     * The fixed, node-wide tools.adminer.v1 pool socket, or null. Non-null only for the node's own
     * hostname (see isNodeToolsHost), never for a customer's domain: Adminer opens on the node's
     * address at /__lesta-adminer__, so an admin tool is never exposed under a tenant's name. It
     * additionally requires web.nginx.v1 (the only renderer of the hand-off), an issued
     * certificate (the hand-off carries a one-time login token), and a non-suspended
     * tools.adminer.v1 capability on the node.
     *
     * Only (re)computed on this WebDomain's own next create/update: enabling tools.adminer.v1 on
     * a node does not retroactively re-render an already-rendered vhost; that domain's own next
     * update (a certificate renewal, say) picks it up.
     */
    private function resolveAdminerSocket(string $capability): ?string
    {
        if ($capability !== 'web.nginx.v1' || $this->certificate_issued_at === null || ! $this->isNodeToolsHost()) {
            return null;
        }

        return $this->node->hasCapability(NodeCapabilityType::Adminer) ? '/run/lesta-adminer/adminer.sock' : null;
    }

    /**
     * The fixed, node-wide mail.webmail.v1 pool socket, or null. Non-null only for the node's own
     * hostname (see isNodeToolsHost), where webmail.conf.tmpl serves Roundcube at "/" instead of
     * any content of this domain's own. It additionally requires web.nginx.v1 (webmail is only
     * ever rendered by nginx), an issued certificate (the template forces HTTP to HTTPS, since it
     * carries login passwords), and a non-suspended mail.webmail.v1 capability on the node.
     *
     * Only (re)computed on this WebDomain's own next create/update, the same accepted limitation
     * resolveAdminerSocket() documents.
     */
    private function resolveWebmailSocket(string $capability): ?string
    {
        if ($capability !== 'web.nginx.v1' || $this->certificate_issued_at === null || ! $this->isNodeToolsHost()) {
            return null;
        }

        return $this->node->hasCapability(NodeCapabilityType::Webmail) ? '/run/lesta-webmail/webmail.sock' : null;
    }

    /**
     * Whether this domain is its node's own hostname: the one vhost per node that hosts the
     * admin-facing tools (webmail and Adminer), so they open on the node's address and never on
     * a customer's domain.
     */
    public function isNodeToolsHost(): bool
    {
        $node = $this->node;

        return $node !== null && strtolower($this->domain) === strtolower($node->hostname);
    }

    /**
     * This domain's own account's real per-node OS identity
     * (system.account-identity.v1, already provisioned by EnsuresAccountNodeIdentity before this
     * domain's own creation ever records a provisioning operation -- see CreateWebDomain's own
     * call site). Shared by toProvisioningPayload() and toPhpFpmProvisioningPayload() below,
     * rather than each resolving it separately.
     */
    private function resolveAccountUsername(): string
    {
        $identity = AccountNodeIdentity::query()
            ->where('account_id', $this->account_id)
            ->where('node_id', $this->node_id)
            ->first();

        if ($identity === null) {
            throw new RuntimeException("No AccountNodeIdentity exists for account {$this->account_id} on node {$this->node_id}; EnsuresAccountNodeIdentity should have created one before this domain's own provisioning operation was ever recorded.");
        }

        return $identity->system_username;
    }

    /**
     * The deterministic socket path web.php-fpm.v1's own pool for this domain listens on, or null
     * when php_version isn't set (static-only). Shared, fixed formula both this method and
     * web.php-fpm.v1's own pool-rendering template independently compute from the same two
     * inputs (php_version, this domain's own uuid) -- never sent from one side to the other,
     * exactly like every other agent-resolved path in this payload.
     */
    private function phpSocketPath(): ?string
    {
        if ($this->php_version === null) {
            return null;
        }

        return "/run/lesta-php/{$this->php_version->value}/{$this->uuid}.sock";
    }

    /**
     * Shape the desired-state payload sent to web.php-fpm.v1 specifically, only ever called when
     * a php version is actually in play (callers gate on that before dispatching this capability
     * at all -- see CreateWebDomain/UpdateWebDomain). account_username is this domain's own
     * account's real per-node OS identity (system.account-identity.v1, already provisioned by
     * EnsuresAccountNodeIdentity before this domain's own creation ever records a provisioning
     * operation): the pool's own user/group directives, never a value this method invents.
     *
     * $version defaults to this domain's own current php_version; UpdateWebDomain passes the
     * *previous* version explicitly when a domain turns PHP off (a Delete operation still needs
     * to know which version's own pool.d directory the fragment being removed lives under, even
     * though the model's own php_version column is already null by the time that delete is
     * recorded).
     *
     * @return array{account_id: int, account_username: string, php_version: string, suspended: bool}
     */
    public function toPhpFpmProvisioningPayload(?PhpVersion $version = null): array
    {
        $version ??= $this->php_version;

        if ($version === null) {
            throw new RuntimeException("toPhpFpmProvisioningPayload() called for web domain {$this->uuid} with no php_version, and none was passed explicitly.");
        }

        return [
            'account_id' => $this->account_id,
            'account_username' => $this->resolveAccountUsername(),
            'php_version' => $version->value,
            'suspended' => $this->isSuspended(),
        ];
    }

    /**
     * Shapes the desired-state payload sent to files.manager.v1: account_username (the exact
     * identity the real file write lands owned by, the same one SFTP and web.php-fpm.v1 already
     * use) merged with whichever fields this one operation actually needs (path, new_path,
     * content_base64, is_directory, recursive) -- callers (App\Actions\Files\*) supply those,
     * since they differ per verb and this method has no verb concept of its own.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public function toFilesManagerProvisioningPayload(array $fields): array
    {
        return array_merge(['account_username' => $this->resolveAccountUsername()], $fields);
    }
}
