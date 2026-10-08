<?php

namespace App\Actions\Nodes;

use App\Enums\SslMode;
use App\Jobs\UpdateWebCapabilityCertificate;
use App\Models\AuditEvent;
use App\Models\Node;
use App\Models\User;
use App\Models\WebDomain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * For a node whose hostname web domain uses a manually installed certificate: records that the
 * operator has put the certificate files in place, and re-sends the domain's nginx configuration
 * so the tools vhost starts serving webmail and Adminer over HTTPS. The control plane cannot see
 * a node's disk, so this is an operator's statement, not a check; if the files are missing, the
 * node's nginx test fails, the previous configuration stays live, and the failure and its reason
 * show on the domain's operation history.
 */
class ConfirmNodeToolsCertificate
{
    public function handle(User $actor, Node $node): WebDomain
    {
        Gate::forUser($actor)->authorize('update', $node);

        $webDomain = WebDomain::query()
            ->where('node_id', $node->id)
            ->where('domain', WebDomain::normalizeDomain($node->hostname))
            ->first();

        if ($webDomain === null || $webDomain->ssl_mode !== SslMode::Manual) {
            throw ValidationException::withMessages([
                'tools_domain' => 'This node has no hostname web domain that uses a manually installed certificate.',
            ]);
        }

        DB::transaction(function () use ($actor, $node, $webDomain): void {
            $webDomain->forceFill([
                'certificate_issued_at' => now(),
                'certificate_authority' => 'manual',
            ])->save();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $node->getMorphClass(),
                'auditable_id' => $node->getKey(),
                'action' => 'node.tools_certificate_confirmed',
                'correlation_id' => (string) Str::uuid(),
            ]);
        });

        UpdateWebCapabilityCertificate::dispatch($webDomain->refresh());

        return $webDomain;
    }
}
