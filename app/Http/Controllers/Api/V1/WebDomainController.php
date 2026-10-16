<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\WebDomainResource;
use App\Models\Account;
use App\Models\WebDomain;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class WebDomainController extends ApiController
{
    public function index(Request $request, Account $account): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [WebDomain::class, $account]);

        $request->validate(['filter.domain' => ['sometimes', 'string']]);

        return WebDomainResource::collection($account->webDomains()
            ->with(['aliases', 'latestProvisioningOperation'])
            ->when($request->filled('filter.domain'), fn ($query) => $query->where('domain', $request->string('filter.domain')->lower()->toString()))
            ->orderBy('id')
            ->cursorPaginate($this->limit($request)));
    }

    public function show(Account $account, WebDomain $webDomain): WebDomainResource
    {
        Gate::authorize('view', $webDomain);

        return new WebDomainResource($webDomain->load(['aliases', 'latestProvisioningOperation']));
    }
}
