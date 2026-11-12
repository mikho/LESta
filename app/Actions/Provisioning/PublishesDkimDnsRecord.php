<?php

namespace App\Actions\Provisioning;

use App\Enums\DnsRecordType;
use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningVerb;
use App\Exceptions\NoDnsCapableNodeAvailableException;
use App\Models\AuditEvent;
use App\Models\DnsZone;
use App\Models\MailDomain;
use App\Models\ProvisioningOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Mirrors RecordsBackupArtifact's role: the one place "a mail domain operation just completed,
 * and it reported a real DKIM public key" knowledge lives. mail.smtp-imap.v1's own
 * MailCapability reports {active: {selector, public_key}, pending?: {...}} on a successful
 * create/update/suspend/unsuspend's ResultEnvelope.Data whenever dkim_enabled is actively true
 * (see agent/internal/capability/mail/dkim.go); this hook publishes the matching
 * "<selector>._domainkey.<domain>" TXT record, for EACH of active and (if present, mid-rotation)
 * pending, to whichever DnsZone, owned by the same account, has a domain exactly matching the
 * mail domain's own -- creating it on first publish, updating it in place if the value ever
 * changes, and doing nothing beyond a logged warning when no matching zone exists or its own node
 * has no active dns.bind9.v1 capability: DNS is a fully separate, independently-managed resource
 * this project has never auto-created on a tenant's behalf (no DnsZone<->MailDomain relationship
 * exists at all), and a provisioning-completion hook has no HTTP request to surface a validation
 * error to. Deliberately bypasses the account's own dns_records PackageLimit: this is an
 * infrastructure-required record for the mail domain the tenant already provisioned, not a
 * tenant-authored one counted against their own quota. A retiring selector's own record is never
 * touched here at all -- deleting it is App\Console\Commands\RetireOldDkimSelectors' own job,
 * once it decides the rotation is fully done, not something this completion hook ever infers.
 */
class PublishesDkimDnsRecord
{
    /** A DER public key in base64: a 2048- or 4096-bit RSA key is well under 1000 characters. */
    private const string PUBLIC_KEY_PATTERN = '/^[A-Za-z0-9+\/]{64,1024}={0,2}$/';

    public function handle(ProvisioningOperation $operation): void
    {
        $mailDomain = $operation->provisionable;
        if (! $mailDomain instanceof MailDomain) {
            return;
        }

        if (! in_array($operation->status, [ProvisioningStatus::Applied, ProvisioningStatus::AlreadyApplied], true)) {
            return;
        }

        $data = $operation->data ?? [];

        foreach (['active', 'pending'] as $role) {
            $entry = $data[$role] ?? null;
            if (! is_array($entry)) {
                continue;
            }

            $selector = $entry['selector'] ?? null;
            $publicKey = $entry['public_key'] ?? null;

            if (! is_string($selector) || $selector === '' || ! is_string($publicKey) || $publicKey === '') {
                continue;
            }

            // The node's answer becomes a public DNS record, so only what the panel asked for is
            // published: one of the selectors it gave this domain, with a key that is plain base64.
            if (! in_array($selector, array_filter([$mailDomain->dkim_selector, $mailDomain->dkim_pending_selector]), true)
                || preg_match(self::PUBLIC_KEY_PATTERN, $publicKey) !== 1) {
                Log::warning('Not publishing a DKIM record the node reported for a selector or key this domain was not given.', [
                    'mail_domain_id' => $mailDomain->id,
                    'selector' => Str::limit($selector, 64),
                ]);

                continue;
            }

            $this->publishSelector($mailDomain, $selector, $publicKey);
        }
    }

    private function publishSelector(MailDomain $mailDomain, string $selector, string $publicKey): void
    {
        $dnsZone = DnsZone::query()
            ->where('account_id', $mailDomain->account_id)
            ->where('domain', $mailDomain->domain)
            ->first();

        if ($dnsZone === null) {
            Log::warning('Cannot publish DKIM DNS record: no matching DnsZone found for this mail domain.', [
                'mail_domain_id' => $mailDomain->id,
                'domain' => $mailDomain->domain,
                'account_id' => $mailDomain->account_id,
            ]);

            return;
        }

        $name = $selector.'._domainkey';
        $value = "v=DKIM1; k=rsa; p={$publicKey}";

        try {
            $this->publish($dnsZone, $name, $value);
        } catch (NoDnsCapableNodeAvailableException $e) {
            Log::warning('Cannot publish DKIM DNS record: '.$e->getMessage(), [
                'mail_domain_id' => $mailDomain->id,
                'dns_zone_id' => $dnsZone->id,
            ]);
        }
    }

    private function publish(DnsZone $dnsZone, string $name, string $value): void
    {
        DB::transaction(function () use ($dnsZone, $name, $value): void {
            $record = $dnsZone->records()->where('name', $name)->where('type', DnsRecordType::TXT)->first();

            if ($record !== null && $record->value === $value) {
                return;
            }

            $correlationId = (string) Str::uuid();

            if ($record !== null) {
                $record->update(['value' => $value]);
                $action = 'dns_record.dkim_updated';
            } else {
                $record = $dnsZone->records()->create([
                    'name' => $name,
                    'type' => DnsRecordType::TXT,
                    'value' => $value,
                ]);
                $action = 'dns_record.dkim_published';
            }

            AuditEvent::record($record, $action, null, $correlationId);

            $dnsZone->forceFill(['desired_state_version' => $dnsZone->desired_state_version + 1])->save();

            $capability = app(ResolvesDnsCapableNode::class)->resolveFor($dnsZone->node);

            app(RecordsProvisioningOperation::class)->record(
                $dnsZone,
                $capability,
                ProvisioningVerb::Update,
                $dnsZone->toProvisioningPayload(),
                $correlationId,
                $dnsZone->desired_state_version,
            );
        });
    }
}
