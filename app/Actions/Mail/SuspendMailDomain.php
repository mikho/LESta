<?php

namespace App\Actions\Mail;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesMailCapableNode;
use App\Enums\ProvisioningVerb;
use App\Enums\SuspensionSource;
use App\Models\AuditEvent;
use App\Models\MailAccount;
use App\Models\MailDomain;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class SuspendMailDomain
{
    public function handle(User $actor, MailDomain $mailDomain, SuspensionSource $source = SuspensionSource::Manual): void
    {
        Gate::forUser($actor)->authorize('suspend', $mailDomain);

        if ($mailDomain->isSuspended()) {
            return; // duplicate submission: no second audit row
        }

        DB::transaction(function () use ($actor, $mailDomain, $source): void {
            $mailDomain->suspend($source);
            $mailDomain->forceFill(['desired_state_version' => $mailDomain->desired_state_version + 1])->save();

            // Cascade: only accounts that are currently active get cascade-suspended. An account
            // already suspended for any reason (including manually) is left untouched, so its
            // existing suspended_at/suspension_source survives a later domain-level unsuspend.
            // This is a raw model-flip cascade (no per-account audit event or provisioning
            // operation), mirroring DnsZone's own identical cascade into DnsRecord.
            $accountsSuspended = $mailDomain->accounts()->whereNull('suspended_at')->get()
                ->each(fn (MailAccount $a) => $a->suspend(SuspensionSource::Cascade))
                ->count();

            $capability = app(ResolvesMailCapableNode::class)->resolveFor($mailDomain->node);
            $correlationId = (string) Str::uuid();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $mailDomain->getMorphClass(),
                'auditable_id' => $mailDomain->getKey(),
                'action' => 'mail_domain.suspended',
                'correlation_id' => $correlationId,
                'metadata' => ['source' => $source->value, 'accounts_suspended' => $accountsSuspended],
            ]);

            app(RecordsProvisioningOperation::class)->record(
                $mailDomain,
                $capability,
                ProvisioningVerb::Suspend,
                $mailDomain->toProvisioningPayload(),
                $correlationId,
                $mailDomain->desired_state_version,
            );
        });
    }
}
