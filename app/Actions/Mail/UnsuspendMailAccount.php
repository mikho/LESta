<?php

namespace App\Actions\Mail;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesMailCapableNode;
use App\Enums\ProvisioningVerb;
use App\Models\AuditEvent;
use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class UnsuspendMailAccount
{
    public function handle(User $actor, MailAccount $mailAccount): void
    {
        Gate::forUser($actor)->authorize('unsuspend', $mailAccount);

        if (! $mailAccount->isSuspended()) {
            return;
        }

        DB::transaction(function () use ($actor, $mailAccount): void {
            $mailAccount->unsuspend();

            // Standalone account unsuspension: recorded as an Update against the domain, not an
            // Unsuspend, since the domain itself does not transition.
            $mailDomain = $mailAccount->mailDomain;
            $mailDomain->bumpDesiredStateVersion();

            $capability = app(ResolvesMailCapableNode::class)->resolveFor($mailDomain->node);
            $correlationId = (string) Str::uuid();

            AuditEvent::record($mailAccount, 'mail_account.unsuspended', $actor, $correlationId);

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
