<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\MailDomainResource;
use App\Models\Account;
use App\Models\MailDomain;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class MailDomainController extends ApiController
{
    public function index(Request $request, Account $account): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [MailDomain::class, $account]);

        $request->validate(['filter.domain' => ['sometimes', 'string']]);

        return MailDomainResource::collection($account->mailDomains()
            ->withCount('accounts')
            ->with('latestProvisioningOperation')
            ->when($request->filled('filter.domain'), fn ($query) => $query->where('domain', $request->string('filter.domain')->lower()->toString()))
            ->orderBy('id')
            ->cursorPaginate($this->limit($request)));
    }

    public function show(Account $account, MailDomain $mailDomain): MailDomainResource
    {
        Gate::authorize('view', $mailDomain);

        return new MailDomainResource($mailDomain->loadCount('accounts')->load('latestProvisioningOperation'));
    }
}
