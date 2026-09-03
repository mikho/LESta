<?php

namespace App\Jobs;

use AcmePhp\Core\Challenge\Dns\DnsDataExtractor;
use AcmePhp\Core\Challenge\Http\HttpDataExtractor;
use AcmePhp\Core\Protocol\AuthorizationChallenge;
use AcmePhp\Ssl\Certificate;
use AcmePhp\Ssl\CertificateRequest;
use AcmePhp\Ssl\CertificateResponse;
use AcmePhp\Ssl\DistinguishedName;
use AcmePhp\Ssl\Generator\KeyPairGenerator;
use App\Actions\Acme\EnsuresAcmeAccountExists;
use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesDnsCapableNode;
use App\Actions\Provisioning\ResolvesWebCapableNode;
use App\Contracts\Provisioner;
use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningVerb;
use App\Enums\SslMode;
use App\Models\DnsZone;
use App\Models\ProvisioningOperation;
use App\Models\WebDomain;
use App\Services\Acme\AcmeClientFactory;
use App\Services\Provisioning\ProvisioningResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * The real ACME v2 protocol client (via acmephp/core), run as a queued job
 * because a real challenge/validation round trip is a multi-step,
 * multi-minute process the strictly one-shot node protocol cannot itself
 * wait on (see internal/capability/acme's own package doc comment on the Go
 * side). This job has normal DB access and unbounded wall-clock time; the Go
 * tls.acme.v1 capability it drives never renders a template, never invokes
 * an external binary, and never health-checks anything -- it only ever
 * writes (or, for a challenge, removes) plain files under its own owned
 * root.
 *
 * The ACME *account* key that signs every request this job makes to the CA
 * never appears anywhere this job touches a queue: it lives only in
 * AcmeAccount::$account_key, encrypted at rest, reconstructed fresh by
 * AcmeClientFactory each time this job runs. A domain's own issued
 * certificate/private key is a different thing entirely -- it must reach the
 * node's disk in cleartext for nginx to terminate TLS, so it travels through
 * a normal ProvisioningOperation payload like any other desired-state field.
 */
class IssueAcmeCertificate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private Provisioner $provisioner;

    /**
     * @param  bool  $preferDns01  Explicit opt-in only: DNS-01 is never selected just because a
     *                             matching DnsZone happens to exist. There is deliberately no
     *                             auto-detection heuristic (see WebDomain::resolveDnsZone()'s own
     *                             doc comment for the same "no evidence yet" reasoning).
     */
    public function __construct(
        public WebDomain $webDomain,
        public bool $preferDns01 = false,
    ) {}

    public function handle(EnsuresAcmeAccountExists $ensuresAcmeAccountExists, AcmeClientFactory $clientFactory, Provisioner $provisioner): void
    {
        $this->provisioner = $provisioner;

        if (! $this->passesGlobalRateLimit()) {
            return;
        }

        $webDomain = $this->webDomain->fresh();
        if ($webDomain === null || $webDomain->ssl_mode !== SslMode::LetsEncrypt) {
            return;
        }

        if ($this->isInBackoffWindow($webDomain)) {
            return;
        }

        $dnsZone = $this->preferDns01 ? $webDomain->resolveDnsZone() : null;
        $useDns01 = $dnsZone !== null;

        $cleanup = null;
        $account = null;

        try {
            // Account setup lives inside this same try block: a
            // registration failure (network error, CA rejection) must be
            // recorded as last_certificate_error and left for the next
            // scheduled attempt like any other failure, never left to
            // propagate uncaught and fail the job outright (which would
            // defeat this job's own bounded-retry design -- see
            // passesGlobalRateLimit/isInBackoffWindow).
            $account = $ensuresAcmeAccountExists->handle();
            $client = $clientFactory->forAccount($account);

            // Deliberately requestOrder()+finalizeOrder() on the SAME
            // CertificateOrder object throughout, never the
            // requestAuthorization()/requestCertificate() convenience pair:
            // requestCertificate() creates a *second*, brand-new order
            // internally, which is not guaranteed to inherit this order's
            // own just-validated authorization (confirmed against a real
            // Pebble instance: the second order's own authorization comes
            // back "pending" again, and finalizing it fails with
            // "Order's status ... was not ready"). Keeping and finalizing
            // the exact order whose authorization was actually validated is
            // the correct ACME v2 flow.
            $order = $client->requestOrder([$webDomain->domain]);
            $challenges = $order->getAuthorizationChallenges($webDomain->domain);
            $challenge = $this->selectChallenge($challenges, $useDns01);

            $cleanup = $useDns01
                ? $this->stageDns01($dnsZone, $challenge)
                : $this->stageHttp01($webDomain, $challenge);

            $client->challengeAuthorization($challenge, (int) config('acme.challenge_timeout', 90));

            $domainKeyPair = (new KeyPairGenerator)->generateKeyPair();
            $csr = new CertificateRequest(new DistinguishedName($webDomain->domain), $domainKeyPair);
            $response = $client->finalizeOrder($order, $csr, (int) config('acme.order_timeout', 90));

            $this->installCertificate($webDomain, $response);

            $webDomain->forceFill([
                'certificate_authority' => (string) parse_url($account->directory_url, PHP_URL_HOST),
                'certificate_issued_at' => now(),
                'certificate_expires_at' => $this->certificateExpiry($response->getCertificate()),
                'last_certificate_error' => null,
            ])->save();

            // Deliberately its own try/catch, never allowed to fall through
            // to the outer catch: the certificate is already successfully
            // issued and installed by this point, so a failure only telling
            // nginx about it (e.g. the node's own web capability was
            // suspended between the original vhost create and this
            // issuance) must never retroactively overwrite that real
            // success as last_certificate_error.
            try {
                $this->dispatchWebCapabilityUpdateIfPresent($webDomain);
            } catch (Throwable $webCapabilityDispatchError) {
                report($webCapabilityDispatchError);
            }
        } catch (Throwable $e) {
            $webDomain->forceFill([
                'last_certificate_error' => Str::limit($e->getMessage(), 500, ''),
            ])->save();

            report($e);
        } finally {
            // Deliberately its own try/catch too: a cleanup failure (e.g. a
            // network error removing the challenge file) must never escape
            // handle() uncaught -- that would let the queue worker mark this
            // job "failed" and retry it per Laravel's own queue semantics,
            // defeating this job's own bounded-retry design (see
            // passesGlobalRateLimit/isInBackoffWindow) entirely.
            try {
                if ($cleanup !== null) {
                    $cleanup();
                }
            } catch (Throwable $cleanupError) {
                report($cleanupError);
            }
        }
    }

    /**
     * Bounds total issuance attempts (across every domain) per hour, the
     * ADR's own "per-account and per-node rate limits" gate.
     */
    private function passesGlobalRateLimit(): bool
    {
        $key = 'acme-issuance';
        $max = (int) config('acme.rate_limit_per_hour', 20);

        if (RateLimiter::tooManyAttempts($key, $max)) {
            return false;
        }

        RateLimiter::hit($key, 3600);

        return true;
    }

    /**
     * Bounds retries for a single domain: a domain whose most recent
     * issuance attempt failed is left alone for retry_after_hours, measured
     * from WebDomain's own updated_at (bumped every time
     * last_certificate_error is written), needing no dedicated
     * next-attempt-at column.
     */
    private function isInBackoffWindow(WebDomain $webDomain): bool
    {
        if ($webDomain->last_certificate_error === null) {
            return false;
        }

        $retryAfterHours = (int) config('acme.retry_after_hours', 6);

        return $webDomain->updated_at !== null && $webDomain->updated_at->gt(now()->subHours($retryAfterHours));
    }

    /**
     * @param  AuthorizationChallenge[]  $challenges
     */
    private function selectChallenge(array $challenges, bool $useDns01): AuthorizationChallenge
    {
        $wanted = $useDns01 ? 'dns-01' : 'http-01';

        foreach ($challenges as $challenge) {
            if ($challenge->getType() === $wanted) {
                return $challenge;
            }
        }

        throw new \RuntimeException("The Certificate Authority did not offer a {$wanted} challenge for this authorization.");
    }

    /**
     * Stages an HTTP-01 challenge by dispatching a real tls.acme.v1 `create`
     * operation directly (bypassing the queue -- this job already *is* the
     * queued context). Returns the cleanup closure that removes the
     * challenge file again, always run in handle()'s own finally block.
     */
    private function stageHttp01(WebDomain $webDomain, AuthorizationChallenge $challenge): \Closure
    {
        $extractor = new HttpDataExtractor;
        $token = $challenge->getToken();
        $keyAuthorization = $extractor->getCheckContent($challenge);

        $payload = [
            'kind' => 'http01_challenge',
            'token' => $token,
            'key_authorization' => $keyAuthorization,
        ];

        $result = $this->applyDirect($webDomain, 'tls.acme.v1', ProvisioningVerb::Create, $payload, $webDomain->desired_state_version);

        if (! $this->isSuccessResult($result)) {
            throw new \RuntimeException('Failed to stage the HTTP-01 challenge via tls.acme.v1: '.$this->describeErrors($result));
        }

        return function () use ($webDomain, $token, $keyAuthorization): void {
            $this->applyDirect($webDomain, 'tls.acme.v1', ProvisioningVerb::Delete, [
                'kind' => 'http01_challenge',
                'token' => $token,
                'key_authorization' => $keyAuthorization,
            ], $webDomain->desired_state_version);
        };
    }

    /**
     * Stages a DNS-01 challenge. The `_acme-challenge` TXT value is
     * ephemeral, system-owned metadata -- it never becomes a real, persisted
     * DnsRecord row a tenant could see or edit. Instead, the zone's own
     * current toProvisioningPayload() is submitted directly with the
     * challenge record injected, applied, then re-submitted without it,
     * restoring the zone to exactly what dns_records itself still holds.
     */
    private function stageDns01(DnsZone $dnsZone, AuthorizationChallenge $challenge): \Closure
    {
        $extractor = new DnsDataExtractor;
        $recordValue = $extractor->getRecordValue($challenge);

        $capability = app(ResolvesDnsCapableNode::class)->resolveFor($dnsZone->node);
        $restorePayload = $dnsZone->toProvisioningPayload();

        $ephemeralPayload = $restorePayload;
        $ephemeralPayload['records'][] = [
            'name' => '_acme-challenge',
            'type' => 'TXT',
            'priority' => null,
            'value' => $recordValue,
            'suspended' => false,
        ];

        $result = $this->applyDirect($dnsZone, $capability, ProvisioningVerb::Update, $ephemeralPayload, $dnsZone->desired_state_version);

        if (! $this->isSuccessResult($result)) {
            throw new \RuntimeException('Failed to stage the DNS-01 ephemeral TXT record: '.$this->describeErrors($result));
        }

        return function () use ($dnsZone, $capability, $restorePayload): void {
            $this->applyDirect($dnsZone, $capability, ProvisioningVerb::Update, $restorePayload, $dnsZone->desired_state_version);
        };
    }

    private function installCertificate(WebDomain $webDomain, CertificateResponse $response): void
    {
        $certificate = $response->getCertificate();

        $fullChainPem = $certificate->getPEM();
        foreach ($certificate->getIssuerChain() as $issuer) {
            $fullChainPem .= $issuer->getPEM();
        }

        $privateKeyPem = $response->getCertificateRequest()->getKeyPair()->getPrivateKey()->getPEM();

        $payload = [
            'kind' => 'certificate',
            'domain' => $webDomain->domain,
            'full_chain_pem' => $fullChainPem,
            'private_key_pem' => $privateKeyPem,
        ];

        $result = $this->applyDirect($webDomain, 'tls.acme.v1', ProvisioningVerb::Update, $payload, $webDomain->desired_state_version);

        if (! $this->isSuccessResult($result)) {
            throw new \RuntimeException('Failed to install the issued certificate via tls.acme.v1: '.$this->describeErrors($result));
        }
    }

    /**
     * Once a certificate is installed, this domain's own resolved PUBLIC
     * web capability needs to be told about it so it actually terminates
     * HTTPS -- web.nginx.v1 when the node has it active (nginx always
     * fronts the public listener, per ResolvesWebCapableNode's own
     * nginx-over-apache precedence), otherwise web.apache.v1. Both
     * capabilities now render an HTTPS vhost template once
     * WebDomain::toProvisioningPayload() populates
     * ssl.certificate_path/private_key_path for them, so neither is
     * special-cased here: the same resolution TriggersAcmeCertificateIssuance
     * itself performs (see its own doc comment) is simply repeated fresh.
     *
     * Unlike the ACME protocol steps above, there is no reason to bypass the
     * queue here: the public capability picking up the new certificate
     * isn't part of the CA's own synchronous validation path, so this
     * reuses the same queued RecordsProvisioningOperation::record() path
     * every other web_domain desired-state change already goes through.
     */
    private function dispatchWebCapabilityUpdateIfPresent(WebDomain $webDomain): void
    {
        $capabilities = app(ResolvesWebCapableNode::class)->resolveFor($webDomain->node, $webDomain->web_server->value);
        $publicCapability = in_array('web.nginx.v1', $capabilities, true) ? 'web.nginx.v1' : 'web.apache.v1';

        app(RecordsProvisioningOperation::class)->record(
            $webDomain,
            $publicCapability,
            ProvisioningVerb::Update,
            $webDomain->toProvisioningPayload($publicCapability),
            (string) Str::uuid(),
            $webDomain->desired_state_version,
        );
    }

    private function certificateExpiry(Certificate $certificate): Carbon
    {
        $parsed = openssl_x509_parse($certificate->getPEM());

        if ($parsed === false || ! isset($parsed['validTo_time_t'])) {
            throw new \RuntimeException('Failed to parse the issued certificate to determine its expiry.');
        }

        return Carbon::createFromTimestamp($parsed['validTo_time_t']);
    }

    /**
     * Applies one provisioning operation directly against the real
     * Provisioner, bypassing the queue entirely: this job already *is* the
     * queued context, so dispatching another job just to immediately wait on
     * it would be pointless indirection. Still creates a real
     * ProvisioningOperation row first, for audit/observability parity with
     * every other capability, and replicates
     * DispatchProvisioningOperation::handle()'s own row-locking/
     * status-writing pattern for this direct-call case.
     *
     * @param  array<string, mixed>  $payload
     */
    private function applyDirect(Model $provisionable, string $capability, ProvisioningVerb $operation, array $payload, int $desiredStateVersion): ProvisioningResult
    {
        return DB::transaction(function () use ($provisionable, $capability, $operation, $payload, $desiredStateVersion): ProvisioningResult {
            $resourceId = $provisionable->uuid; // @phpstan-ignore property.notFound

            $row = ProvisioningOperation::create([
                'provisionable_type' => $provisionable->getMorphClass(),
                'provisionable_id' => $provisionable->getKey(),
                'resource_id' => $resourceId,
                'capability' => $capability,
                'operation' => $operation,
                'status' => ProvisioningStatus::Pending,
                'desired_state_version' => $desiredStateVersion,
                'payload' => $payload,
                'correlation_id' => (string) Str::uuid(),
                'idempotency_key' => (string) Str::uuid(),
                'issued_at' => now(),
                'request_digest' => 'sha256:'.hash('sha256', json_encode([
                    $capability, $operation->value, $resourceId, $desiredStateVersion, $payload,
                ], JSON_THROW_ON_ERROR)),
            ]);

            $row = ProvisioningOperation::query()->whereKey($row->id)->lockForUpdate()->first();

            $row->forceFill([
                'status' => ProvisioningStatus::Dispatched,
                'dispatched_at' => now(),
                'attempts' => $row->attempts + 1,
                'deadline' => now()->addMinutes((int) config('provisioning.dispatch_deadline_minutes', 5)),
            ])->save();

            $result = $this->provisioner->apply($row);

            $row->forceFill([
                'status' => $result->status,
                'observed_state_version' => $result->observedStateVersion,
                'observed_state_digest' => $result->observedStateDigest,
                'generation_id' => $result->generationId,
                'errors' => $result->errors,
                'completed_at' => $result->completedAt,
            ])->save();

            return $result;
        });
    }

    private function isSuccessResult(ProvisioningResult $result): bool
    {
        return in_array($result->status, [ProvisioningStatus::Applied, ProvisioningStatus::AlreadyApplied], true);
    }

    private function describeErrors(ProvisioningResult $result): string
    {
        if ($result->errors === []) {
            return "status={$result->status->value}";
        }

        return implode('; ', array_map(fn (array $e): string => $e['code'].': '.$e['message'], $result->errors));
    }
}
