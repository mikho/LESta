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

class DeleteMailAccount
{
    public function handle(User $actor, MailAccount $mailAccount): void
    {
        Gate::forUser($actor)->authorize('delete', $mailAccount);

        DB::transaction(function () use ($actor, $mailAccount): void {
            $mailDomain = $mailAccount->mailDomain;
            $accountId = $mailAccount->id;
            $accountMorphClass = $mailAccount->getMorphClass();

            $correlationId = (string) Str::uuid();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $accountMorphClass,
                'auditable_id' => $accountId,
                'action' => 'mail_account.deleted',
                'correlation_id' => $correlationId,
            ]);

            $mailAccount->delete();

            $mailDomain->forceFill(['desired_state_version' => $mailDomain->desired_state_version + 1])->save();

            $capability = app(ResolvesMailCapableNode::class)->resolveFor($mailDomain->node);

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
