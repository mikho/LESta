<?php

namespace App\Console\Commands;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesMailCapableNode;
use App\Enums\ProvisioningVerb;
use App\Models\AuditEvent;
use App\Models\MailDomain;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Starts a new DKIM selector rotation for every mail domain whose currently active selector has
 * been in place for at least ROTATION_INTERVAL_DAYS: real, automatic rotation on a fixed schedule
 * rather than an on-demand trigger, the user's own choice given that no mail domain UI or HTTP
 * route exists anywhere in this app yet to attach a "rotate now" button to.
 *
 * Generates the next selector's own real key and reports it on ResultEnvelope.Data (see
 * agent/internal/capability/mail/dkim.go's own "pending" shape), which
 * App\Actions\Provisioning\PublishesDkimDnsRecord already publishes as a real DNS TXT record --
 * without switching signing over to it yet. App\Console\Commands\PromotePendingDkimSelectors does
 * that later, once App\Actions\Mail\ResolvesDkimPropagationWindow says enough time has passed for
 * that new record to have propagated safely.
 */
class RotateDkimSelectors extends Command
{
    private const ROTATION_INTERVAL_DAYS = 90;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mail:rotate-dkim-selectors';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Start a new DKIM selector rotation for every mail domain due for one.';

    public function __construct(private readonly ResolvesMailCapableNode $resolvesMailCapableNode)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $due = MailDomain::query()
            ->where('dkim_enabled', true)
            ->whereNull('suspended_at')
            ->whereNull('dkim_pending_selector')
            ->whereNull('dkim_retiring_selector')
            ->whereNotNull('dkim_selector_activated_at')
            ->where('dkim_selector_activated_at', '<=', now()->subDays(self::ROTATION_INTERVAL_DAYS))
            ->get();

        foreach ($due as $mailDomain) {
            $this->startRotation($mailDomain);
        }

        $this->info("Started {$due->count()} DKIM selector rotation(s).");

        return self::SUCCESS;
    }

    private function startRotation(MailDomain $mailDomain): void
    {
        DB::transaction(function () use ($mailDomain): void {
            $mailDomain->forceFill([
                'dkim_pending_selector' => $mailDomain->nextDkimSelector(),
                'dkim_pending_selector_published_at' => now(),
                'desired_state_version' => $mailDomain->desired_state_version + 1,
            ])->save();

            $correlationId = (string) Str::uuid();

            AuditEvent::create([
                'actor_type' => null,
                'actor_id' => null,
                'auditable_type' => $mailDomain->getMorphClass(),
                'auditable_id' => $mailDomain->getKey(),
                'action' => 'mail_domain.dkim_rotation_started',
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
