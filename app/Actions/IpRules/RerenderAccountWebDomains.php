<?php

namespace App\Actions\IpRules;

use App\Actions\Domains\RerenderWebDomain;
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
            app(RerenderWebDomain::class)->handle($webDomain, $correlationId);
        }
    }
}
