<?php

namespace App\Actions\IpRules;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesWebCapableNode;
use App\Enums\ProvisioningVerb;
use App\Models\Account;
use App\Models\WebDomain;
use Illuminate\Support\Str;

/**
 * Re-renders every web domain of an account on its node's nginx, so a changed IP access list
 * takes effect everywhere at once. The list is rendered into each vhost, so each domain gets its
 * own web.nginx.v1 update carrying the current payload. A domain on a node with no nginx
 * capability (Apache only) has no vhost the list could be rendered into and is skipped.
 */
class RerenderAccountWebDomains
{
    public function handle(Account $account): void
    {
        $correlationId = (string) Str::uuid();

        foreach ($account->webDomains()->with(['node', 'ipAllocation'])->get() as $webDomain) {
            /** @var WebDomain $webDomain */
            $capabilities = app(ResolvesWebCapableNode::class)->resolveFor($webDomain->node, $webDomain->web_server->value);

            if (! in_array('web.nginx.v1', $capabilities, true)) {
                continue;
            }

            app(RecordsProvisioningOperation::class)->record(
                $webDomain,
                'web.nginx.v1',
                ProvisioningVerb::Update,
                $webDomain->toProvisioningPayload('web.nginx.v1'),
                $correlationId,
                $webDomain->desired_state_version,
            );
        }
    }
}
