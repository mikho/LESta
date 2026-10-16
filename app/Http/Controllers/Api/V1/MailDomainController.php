<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Mail\CreateMailDomain;
use App\Actions\Mail\DeleteMailDomain;
use App\Actions\Mail\SuspendMailDomain;
use App\Actions\Mail\UnsuspendMailDomain;
use App\Actions\Mail\UpdateMailDomain;
use App\Http\Requests\Mail\StoreMailDomainRequest;
use App\Http\Requests\Mail\UpdateMailDomainRequest;
use App\Http\Resources\Api\V1\MailDomainResource;
use App\Models\Account;
use App\Models\MailDomain;
use Illuminate\Http\JsonResponse;
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

    public function store(StoreMailDomainRequest $request, Account $account): JsonResponse
    {
        $mailDomain = app(CreateMailDomain::class)->handle($request->user(), $account, $request->validated());

        return $this->accepted($request, $mailDomain, $this->fresh($mailDomain));
    }

    /**
     * The panel's update already keeps every field left out, so it serves as a PATCH as it is.
     */
    public function update(UpdateMailDomainRequest $request, Account $account, MailDomain $mailDomain): JsonResponse
    {
        app(UpdateMailDomain::class)->handle($request->user(), $mailDomain, $request->validated());

        return $this->accepted($request, $mailDomain, $this->fresh($mailDomain));
    }

    public function suspend(Request $request, Account $account, MailDomain $mailDomain): JsonResponse
    {
        app(SuspendMailDomain::class)->handle($request->user(), $mailDomain);

        return $this->accepted($request, $mailDomain, $this->fresh($mailDomain));
    }

    public function unsuspend(Request $request, Account $account, MailDomain $mailDomain): JsonResponse
    {
        app(UnsuspendMailDomain::class)->handle($request->user(), $mailDomain);

        return $this->accepted($request, $mailDomain, $this->fresh($mailDomain));
    }

    public function destroy(Request $request, Account $account, MailDomain $mailDomain): JsonResponse
    {
        app(DeleteMailDomain::class)->handle($request->user(), $mailDomain);

        return $this->accepted($request, $mailDomain, null);
    }

    private function fresh(MailDomain $mailDomain): MailDomainResource
    {
        return new MailDomainResource($mailDomain->refresh()->loadCount('accounts')->load('latestProvisioningOperation'));
    }
}
