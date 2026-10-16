<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Domains\CreateWebDomain;
use App\Actions\Domains\DeleteWebDomain;
use App\Actions\Domains\SuspendWebDomain;
use App\Actions\Domains\UnsuspendWebDomain;
use App\Actions\Domains\UpdateWebDomain;
use App\Http\Requests\Api\V1\PatchWebDomainRequest;
use App\Http\Requests\Domains\StoreWebDomainRequest;
use App\Http\Resources\Api\V1\WebDomainResource;
use App\Models\Account;
use App\Models\WebDomain;
use Illuminate\Http\JsonResponse;
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

    public function store(StoreWebDomainRequest $request, Account $account): JsonResponse
    {
        $webDomain = app(CreateWebDomain::class)->handle($request->user(), $account, $request->validated());

        return $this->accepted($request, $webDomain, $this->fresh($webDomain));
    }

    public function update(PatchWebDomainRequest $request, Account $account, WebDomain $webDomain): JsonResponse
    {
        app(UpdateWebDomain::class)->handle($request->user(), $webDomain, $request->validated());

        return $this->accepted($request, $webDomain, $this->fresh($webDomain));
    }

    public function suspend(Request $request, Account $account, WebDomain $webDomain): JsonResponse
    {
        app(SuspendWebDomain::class)->handle($request->user(), $webDomain);

        return $this->accepted($request, $webDomain, $this->fresh($webDomain));
    }

    public function unsuspend(Request $request, Account $account, WebDomain $webDomain): JsonResponse
    {
        app(UnsuspendWebDomain::class)->handle($request->user(), $webDomain);

        return $this->accepted($request, $webDomain, $this->fresh($webDomain));
    }

    public function destroy(Request $request, Account $account, WebDomain $webDomain): JsonResponse
    {
        app(DeleteWebDomain::class)->handle($request->user(), $webDomain);

        return $this->accepted($request, $webDomain, null);
    }

    private function fresh(WebDomain $webDomain): WebDomainResource
    {
        return new WebDomainResource($webDomain->refresh()->load(['aliases', 'latestProvisioningOperation']));
    }
}
