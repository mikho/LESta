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

class DeleteWebDomain
{
    public function handle(User $actor, WebDomain $webDomain): void
    {
        Gate::forUser($actor)->authorize('delete', $webDomain);

        DB::transaction(function () use ($actor, $webDomain): void {
            if ($webDomain->isSuspended()) {
                $webDomain->unsuspend();
            }

            $capabilities = app(ResolvesWebCapableNode::class)->resolveFor($webDomain->node, $webDomain->web_server->value);
            $correlationId = (string) Str::uuid();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $webDomain->getMorphClass(),
                'auditable_id' => $webDomain->getKey(),
                'action' => 'web_domain.deleted',
                'correlation_id' => $correlationId,
            ]);

            foreach ($capabilities as $capability) {
                app(RecordsProvisioningOperation::class)->record(
                    $webDomain,
                    $capability,
                    ProvisioningVerb::Delete,
                    $webDomain->toProvisioningPayload($capability),
                    $correlationId,
                    $webDomain->desired_state_version,
                );
            }

            $webDomain->delete();
        });
    }
}
