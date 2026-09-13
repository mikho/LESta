<?php

namespace App\Console\Commands;

use App\Actions\Mail\ResolvesDkimPropagationWindow;
use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesMailCapableNode;
use App\Enums\ProvisioningVerb;
use App\Models\AuditEvent;
use App\Models\MailDomain;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The second phase of DKIM selector rotation (see App\Console\Commands\RotateDkimSelectors for
 * the first): once a pending selector's own DNS TXT record has had enough time to propagate (per
 * App\Actions\Mail\ResolvesDkimPropagationWindow, not a guessed fixed duration), switches signing
 * over to it for real. The selector it replaces becomes "retiring": its own DNS record and key
 * material are deliberately left untouched here -- App\Console\Commands\RetireOldDkimSelectors
 * cleans those up later, once its own propagation window has passed too.
 */
class PromotePendingDkimSelectors extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mail:promote-pending-dkim-selectors';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Switch DKIM signing over to a domain's own pending selector once its DNS record has safely propagated.";

    public function __construct(
        private readonly ResolvesDkimPropagationWindow $resolvesDkimPropagationWindow,
        private readonly ResolvesMailCapableNode $resolvesMailCapableNode,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $candidates = MailDomain::query()
            ->whereNotNull('dkim_pending_selector')
            ->whereNotNull('dkim_pending_selector_published_at')
            ->whereNull('suspended_at')
            ->get();

        $promoted = 0;

        foreach ($candidates as $mailDomain) {
            if ($mailDomain->dkim_pending_selector_published_at->greaterThan($this->resolvesDkimPropagationWindow->cutoffFor($mailDomain))) {
                continue;
            }

            $this->promote($mailDomain);
            $promoted++;
        }

        $this->info("Promoted {$promoted} pending DKIM selector(s).");

        return self::SUCCESS;
    }

    private function promote(MailDomain $mailDomain): void
    {
        DB::transaction(function () use ($mailDomain): void {
            $retiringSelector = $mailDomain->dkim_selector;

            $mailDomain->forceFill([
                'dkim_selector' => $mailDomain->dkim_pending_selector,
                'dkim_selector_activated_at' => now(),
                'dkim_pending_selector' => null,
                'dkim_pending_selector_published_at' => null,
                'dkim_retiring_selector' => $retiringSelector,
                'dkim_retiring_selector_demoted_at' => now(),
                'desired_state_version' => $mailDomain->desired_state_version + 1,
            ])->save();

            $correlationId = (string) Str::uuid();

            AuditEvent::create([
                'actor_type' => null,
                'actor_id' => null,
                'auditable_type' => $mailDomain->getMorphClass(),
                'auditable_id' => $mailDomain->getKey(),
                'action' => 'mail_domain.dkim_selector_promoted',
                'correlation_id' => $correlationId,
            ]);

            $capability = $this->resolvesMailCapableNode->resolveFor($mailDomain->node);

            app(RecordsProvisioningOperation::class)->record(
                $mailDomain,
                $capability,
                ProvisioningVerb::Update,
                $mailDomain->toProvisioningPayload(),
                $correlationId,
                $mailDomain->desired_state_version,
            );
        });
    }
}
