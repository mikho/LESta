<?php

namespace App\Console\Commands;

use App\Actions\Dns\DeleteDnsRecord;
use App\Actions\Mail\ResolvesDkimPropagationWindow;
use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesMailCapableNode;
use App\Enums\DnsRecordType;
use App\Enums\ProvisioningVerb;
use App\Models\AuditEvent;
use App\Models\DnsZone;
use App\Models\MailDomain;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The third and final phase of DKIM selector rotation (see App\Console\Commands\
 * RotateDkimSelectors and App\Console\Commands\PromotePendingDkimSelectors for the first two):
 * once a demoted selector's own real "stop signing with it" propagation window has passed (per
 * App\Actions\Mail\ResolvesDkimPropagationWindow), deletes its DNS TXT record and its real key
 * material on the node for good. Nothing before this step ever touched either -- the whole point
 * of a retiring selector is that its DNS record and key stay valid for as long as any mail signed
 * with it before promotion might still be in flight.
 */
class RetireOldDkimSelectors extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mail:retire-old-dkim-selectors';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Delete a domain's own old DKIM selector's DNS record and key material once it is safe to.";

    public function __construct(
        private readonly ResolvesDkimPropagationWindow $resolvesDkimPropagationWindow,
        private readonly ResolvesMailCapableNode $resolvesMailCapableNode,
        private readonly DeleteDnsRecord $deleteDnsRecord,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $candidates = MailDomain::query()
            ->whereNotNull('dkim_retiring_selector')
            ->whereNotNull('dkim_retiring_selector_demoted_at')
            ->whereNull('suspended_at')
            ->get();

        $retired = 0;

        foreach ($candidates as $mailDomain) {
            if ($mailDomain->dkim_retiring_selector_demoted_at->greaterThan($this->resolvesDkimPropagationWindow->cutoffFor($mailDomain))) {
                continue;
            }

            $this->retire($mailDomain);
            $retired++;
        }

        $this->info("Retired {$retired} old DKIM selector(s).");

        return self::SUCCESS;
    }

    private function retire(MailDomain $mailDomain): void
    {
        DB::transaction(function () use ($mailDomain): void {
            $retiringSelector = $mailDomain->dkim_retiring_selector;

            $this->deleteDnsRecordIfPresent($mailDomain, $retiringSelector);

            $mailDomain->forceFill([
                'dkim_retiring_selector' => null,
                'dkim_retiring_selector_demoted_at' => null,
                'desired_state_version' => $mailDomain->desired_state_version + 1,
            ])->save();

            $correlationId = (string) Str::uuid();

            AuditEvent::create([
                'actor_type' => null,
                'actor_id' => null,
                'auditable_type' => $mailDomain->getMorphClass(),
                'auditable_id' => $mailDomain->getKey(),
                'action' => 'mail_domain.dkim_selector_retired',
                'correlation_id' => $correlationId,
            ]);

            $capability = $this->resolvesMailCapableNode->resolveFor($mailDomain->node);

            app(RecordsProvisioningOperation::class)->record(
                $mailDomain,
                $capability,
                ProvisioningVerb::Update,
                $mailDomain->toProvisioningPayload(retireSelector: $retiringSelector),
                $correlationId,
                $mailDomain->desired_state_version,
            );
        });
    }

    /**
     * Mirrors PublishesDkimDnsRecord's own zone-matching lookup exactly: DNS for this domain may
     * not even be managed by this control plane at all (no DnsZone<->MailDomain relationship
     * exists), or the record may already be gone for some other reason -- either way, there is
     * nothing to delete, not an error.
     */
    private function deleteDnsRecordIfPresent(MailDomain $mailDomain, string $selector): void
    {
        $dnsZone = DnsZone::query()
            ->where('account_id', $mailDomain->account_id)
            ->where('domain', $mailDomain->domain)
            ->first();

        if ($dnsZone === null) {
            return;
        }

        $record = $dnsZone->records()->where('name', $selector.'._domainkey')->where('type', DnsRecordType::TXT)->first();

        if ($record === null) {
            return;
        }

        $this->deleteDnsRecord->handleSystemInitiated($record);
    }
}
