<?php

namespace App\Actions\Domains;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesWebCapableNode;
use App\Enums\ProvisioningVerb;
use App\Models\WebDomain;
use Illuminate\Support\Str;

/**
 * Re-renders one web domain's nginx vhost with its current payload, for settings the nginx
 * capability renders from other tables (redirects, the account's IP rules). A domain on a node
 * with no nginx capability has no vhost to render into and is skipped.
 */
class RerenderWebDomain
{
    public function handle(WebDomain $webDomain, ?string $correlationId = null): void
    {
        $capabilities = app(ResolvesWebCapableNode::class)->resolveFor($webDomain->node, $webDomain->web_server->value);

        if (! in_array('web.nginx.v1', $capabilities, true)) {
            return;
        }

        app(RecordsProvisioningOperation::class)->record(
            $webDomain,
            'web.nginx.v1',
            ProvisioningVerb::Update,
            $webDomain->toProvisioningPayload('web.nginx.v1'),
            $correlationId ?? (string) Str::uuid(),
            $webDomain->desired_state_version,
        );
    }
}
