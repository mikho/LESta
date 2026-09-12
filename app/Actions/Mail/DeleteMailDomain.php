<?php

namespace App\Actions\Mail;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesMailCapableNode;
use App\Enums\ProvisioningVerb;
use App\Models\AuditEvent;
use App\Models\MailDomain;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class DeleteMailDomain
{
    public function handle(User $actor, MailDomain $mailDomain): void
    {
        Gate::forUser($actor)->authorize('delete', $mailDomain);

        DB::transaction(function () use ($actor, $mailDomain): void {
            if ($mailDomain->isSuspended()) {
                $mailDomain->unsuspend();
            }

            $capability = app(ResolvesMailCapableNode::class)->resolveFor($mailDomain->node);
            $correlationId = (string) Str::uuid();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $mailDomain->getMorphClass(),
                'auditable_id' => $mailDomain->getKey(),
                'action' => 'mail_domain.deleted',
                'correlation_id' => $correlationId,
            ]);

            app(RecordsProvisioningOperation::class)->record(
                $mailDomain,
                $capability,
                ProvisioningVerb::Delete,
                $mailDomain->toProvisioningPayload(),
                $correlationId,
                $mailDomain->desired_state_version,
            );

            // Child MailAccount rows are removed by the FK's cascadeOnDelete(); no explicit
            // per-account calls needed.
            $mailDomain->delete();
        });
    }
}
