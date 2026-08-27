<?php

namespace App\Actions\Domains;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesWebCapableNode;
use App\Enums\ProvisioningVerb;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\WebDomain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class UnsuspendWebDomain
{
    public function handle(User $actor, WebDomain $webDomain): void
    {
        Gate::forUser($actor)->authorize('unsuspend', $webDomain);

        if (! $webDomain->isSuspended()) {
            return;
        }

        DB::transaction(function () use ($actor, $webDomain): void {
            $webDomain->unsuspend();
            $webDomain->forceFill(['desired_state_version' => $webDomain->desired_state_version + 1])->save();

            $capability = app(ResolvesWebCapableNode::class)->resolveFor($webDomain->node);
            $correlationId = (string) Str::uuid();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $webDomain->getMorphClass(),
                'auditable_id' => $webDomain->getKey(),
                'action' => 'web_domain.unsuspended',
                'correlation_id' => $correlationId,
            ]);

            app(RecordsProvisioningOperation::class)->record(
                $webDomain,
                $capability,
                ProvisioningVerb::Unsuspend,
                $webDomain->toProvisioningPayload(),
                $correlationId,
                $webDomain->desired_state_version,
            );
        });
    }
}
