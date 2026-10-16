<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Mail\CreateMailAccount;
use App\Actions\Mail\DeleteMailAccount;
use App\Actions\Mail\RotateMailAccountPassword;
use App\Actions\Mail\SuspendMailAccount;
use App\Actions\Mail\UnsuspendMailAccount;
use App\Actions\Mail\UpdateMailAccount;
use App\Http\Requests\Mail\StoreMailAccountRequest;
use App\Http\Requests\Mail\UpdateMailAccountRequest;
use App\Http\Resources\Api\V1\MailAccountResource;
use App\Models\Account;
use App\Models\MailAccount;
use App\Models\MailDomain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class MailAccountController extends ApiController
{
    public function index(Request $request, Account $account, MailDomain $mailDomain): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [MailAccount::class, $mailDomain]);

        return MailAccountResource::collection($mailDomain->accounts()
            ->with('mailDomain')
            ->orderBy('id')
            ->cursorPaginate($this->limit($request)));
    }

    public function show(Account $account, MailDomain $mailDomain, MailAccount $mailbox): MailAccountResource
    {
        Gate::authorize('view', $mailbox);

        return new MailAccountResource($mailbox);
    }

    /**
     * The answer carries the new mailbox's password once (data.password).
     */
    public function store(StoreMailAccountRequest $request, Account $account, MailDomain $mailDomain): JsonResponse
    {
        [$mailbox, $password] = app(CreateMailAccount::class)->handle($request->user(), $mailDomain, $request->validated());

        return $this->accepted($request, $mailbox, new MailAccountResource($mailbox->refresh()), ['password' => $password]);
    }

    public function update(UpdateMailAccountRequest $request, Account $account, MailDomain $mailDomain, MailAccount $mailbox): JsonResponse
    {
        app(UpdateMailAccount::class)->handle($request->user(), $mailbox, $request->validated());

        return $this->accepted($request, $mailbox, new MailAccountResource($mailbox->refresh()));
    }

    /**
     * A new password, in this answer only (data.password).
     */
    public function rotatePassword(Request $request, Account $account, MailDomain $mailDomain, MailAccount $mailbox): JsonResponse
    {
        [, $password] = app(RotateMailAccountPassword::class)->handle($request->user(), $mailbox);

        return $this->accepted($request, $mailbox, new MailAccountResource($mailbox->refresh()), ['password' => $password]);
    }

    public function suspend(Request $request, Account $account, MailDomain $mailDomain, MailAccount $mailbox): JsonResponse
    {
        app(SuspendMailAccount::class)->handle($request->user(), $mailbox);

        return $this->accepted($request, $mailbox, new MailAccountResource($mailbox->refresh()));
    }

    public function unsuspend(Request $request, Account $account, MailDomain $mailDomain, MailAccount $mailbox): JsonResponse
    {
        app(UnsuspendMailAccount::class)->handle($request->user(), $mailbox);

        return $this->accepted($request, $mailbox, new MailAccountResource($mailbox->refresh()));
    }

    public function destroy(Request $request, Account $account, MailDomain $mailDomain, MailAccount $mailbox): JsonResponse
    {
        app(DeleteMailAccount::class)->handle($request->user(), $mailbox);

        return $this->accepted($request, $mailbox, null);
    }
}
