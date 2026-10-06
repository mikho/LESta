<?php

namespace App\Jobs;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesWebCapableNode;
use App\Enums\ProvisioningVerb;
use App\Models\WebDomain;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Tells a domain's resolved public web capability about its freshly issued
 * certificate, as its own retried job rather than inline in
 * IssueAcmeCertificate: retrying the whole issuance would request a new
 * certificate each time (ACME rate limits), while this only re-records the
 * web capability's update. If every attempt fails, the domain carries a
 * last_certificate_error saying the certificate is issued but not yet served,
 * instead of the failure only ever reaching the log.
 */
class UpdateWebCapabilityCertificate implements ShouldQueue
{
    use Queueable;

    public const string ERROR_PREFIX = 'Certificate issued, but the web server could not be updated to use it: ';

    public int $tries = 5;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 60, 300, 900];

    public function __construct(public WebDomain $webDomain) {}

    public function handle(): void
    {
        $webDomain = $this->webDomain->fresh();
        if ($webDomain === null || $webDomain->certificate_issued_at === null) {
            return;
        }

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

        if (str_starts_with((string) $webDomain->last_certificate_error, self::ERROR_PREFIX)) {
            $webDomain->forceFill(['last_certificate_error' => null])->save();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $webDomain = $this->webDomain->fresh();
        if ($webDomain === null) {
            return;
        }

        $webDomain->forceFill([
            'last_certificate_error' => Str::limit(self::ERROR_PREFIX.($exception?->getMessage() ?? 'unknown error'), 500, ''),
        ])->save();
    }
}
