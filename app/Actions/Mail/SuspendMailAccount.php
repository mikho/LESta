<?php

namespace App\Actions\Mail;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesMailCapableNode;
use App\Enums\ProvisioningVerb;
use App\Enums\SuspensionSource;
use App\Models\AuditEvent;
use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class SuspendMailAccount
{
    public function handle(User $actor, MailAccount $mailAccount, SuspensionSource $source = SuspensionSource::Manual): void
    {
        Gate::forUser($actor)->authorize('suspend', $mailAccount);

        if ($mailAccount->isSuspended()) {
            return; // duplicate submission: no second audit row
        }

        DB::transaction(function () use ($actor, $mailAccount, $source): void {
            $mailAccount->suspend($source);

            // Standalone account suspension (not part of a domain cascade): the domain itself
            // does not transition, so this is recorded as an Update against the domain, not a
            // Suspend, mirroring DnsRecord's own identical precedent.
            $mailDomain = $mailAccount->mailDomain;
            $mailDomain->forceFill(['desired_state_version' => $mailDomain->desired_state_version + 1])->save();

            $capability = app(ResolvesMailCapableNode::class)->resolveFor($mailDomain->node);
            $correlationId = (string) Str::uuid();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $mailAccount->getMorphClass(),
                'auditable_id' => $mailAccount->getKey(),
                'action' => 'mail_account.suspended',
                'correlation_id' => $correlationId,
                'metadata' => ['source' => $source->value],
            ]);

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
