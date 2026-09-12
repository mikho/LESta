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

class UnsuspendMailDomain
{
    public function handle(User $actor, MailDomain $mailDomain): void
    {
        Gate::forUser($actor)->authorize('unsuspend', $mailDomain);

        if (! $mailDomain->isSuspended()) {
            return;
        }

        DB::transaction(function () use ($actor, $mailDomain): void {
            $mailDomain->unsuspend();
            $mailDomain->forceFill(['desired_state_version' => $mailDomain->desired_state_version + 1])->save();

            // Reactivate only cascade-sourced accounts; a manually-suspended account stays
            // suspended through a domain-level unsuspend.
            $accountsUnsuspended = $mailDomain->accounts()->where('suspension_source', SuspensionSource::Cascade)->get()
                ->each(fn (MailAccount $a) => $a->unsuspend())
                ->count();

            $capability = app(ResolvesMailCapableNode::class)->resolveFor($mailDomain->node);
            $correlationId = (string) Str::uuid();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $mailDomain->getMorphClass(),
                'auditable_id' => $mailDomain->getKey(),
                'action' => 'mail_domain.unsuspended',
                'correlation_id' => $correlationId,
                'metadata' => ['accounts_unsuspended' => $accountsUnsuspended],
            ]);

            app(RecordsProvisioningOperation::class)->record(
                $mailDomain,
                $capability,
                ProvisioningVerb::Unsuspend,
                $mailDomain->toProvisioningPayload(),
                $correlationId,
                $mailDomain->desired_state_version,
            );
        });
    }
}
