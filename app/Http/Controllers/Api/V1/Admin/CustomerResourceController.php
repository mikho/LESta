<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\CronJobResource;
use App\Http\Resources\Api\V1\DnsZoneResource;
use App\Http\Resources\Api\V1\MailDomainResource;
use App\Http\Resources\Api\V1\TenantDatabaseResource;
use App\Http\Resources\Api\V1\WebDomainResource;
use App\Models\CronJob;
use App\Models\DnsZone;
use App\Models\MailDomain;
use App\Models\TenantDatabase;
use App\Models\WebDomain;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Read-only lists of every customer's resources, the API side of the panel's cross-account views:
 * each needs its own *.view_any permission, and none of them grants anything on the resource
 * itself. Resources of the hidden platform account are left out, as in the panel.
 */
class CustomerResourceController extends ApiController
{
    public function webDomains(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAnyAcrossAccounts', WebDomain::class);

        return WebDomainResource::collection($this->list($request, WebDomain::query()->with(['aliases', 'latestProvisioningOperation']), domain: true));
    }

    public function dnsZones(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAnyAcrossAccounts', DnsZone::class);

        return DnsZoneResource::collection($this->list($request, DnsZone::query()->withCount('records')->with('latestProvisioningOperation'), domain: true));
    }

    public function mailDomains(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAnyAcrossAccounts', MailDomain::class);

        return MailDomainResource::collection($this->list($request, MailDomain::query()->withCount('accounts')->with('latestProvisioningOperation'), domain: true));
    }

    public function databases(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAnyAcrossAccounts', TenantDatabase::class);

        return TenantDatabaseResource::collection($this->list($request, TenantDatabase::query()->with('latestProvisioningOperation'), domain: false));
    }

    public function cronJobs(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAnyAcrossAccounts', CronJob::class);

        return CronJobResource::collection($this->list($request, CronJob::query()->with('latestProvisioningOperation'), domain: false));
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return CursorPaginator<int, TModel>
     */
    private function list(Request $request, Builder $query, bool $domain)
    {
        $request->validate(['filter.account' => ['sometimes', 'string'], 'filter.domain' => ['sometimes', 'string']]);

        return $query
            ->with('account:id,public_id')
            ->whereHas('account', fn ($account) => $account->where('is_platform', false)
                ->when($request->filled('filter.account'), fn ($account) => $account->where('public_id', $request->input('filter.account'))))
            ->when($domain && $request->filled('filter.domain'), fn ($query) => $query->where('domain', $request->string('filter.domain')->lower()->toString()))
            ->orderBy('id')
            ->cursorPaginate($this->limit($request));
    }
}
