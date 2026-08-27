<?php

namespace App\Actions\Domains;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesWebCapableNode;
use App\Enums\ProvisioningVerb;
use App\Enums\SuspensionSource;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\WebDomain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class SuspendWebDomain
{
    public function handle(User $actor, WebDomain $webDomain, SuspensionSource $source = SuspensionSource::Manual): void
    {
        Gate::forUser($actor)->authorize('suspend', $webDomain);

        if ($webDomain->isSuspended()) {
            return; // duplicate submission: no second audit row
        }

        DB::transaction(function () use ($actor, $webDomain, $source): void {
            $webDomain->suspend($source);
            $webDomain->forceFill(['desired_state_version' => $webDomain->desired_state_version + 1])->save();

            $capability = app(ResolvesWebCapableNode::class)->resolveFor($webDomain->node);
            $correlationId = (string) Str::uuid();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $webDomain->getMorphClass(),
                'auditable_id' => $webDomain->getKey(),
                'action' => 'web_domain.suspended',
                'correlation_id' => $correlationId,
                'metadata' => ['source' => $source->value],
            ]);

            app(RecordsProvisioningOperation::class)->record(
                $webDomain,
                $capability,
                ProvisioningVerb::Suspend,
                $webDomain->toProvisioningPayload(),
                $correlationId,
                $webDomain->desired_state_version,
            );
        });
    }
}
